#!/bin/bash
# Symbolic link protection: a site may only follow a symbolic link when the
# link and the file it points to have the same owner.
#
# The web server can read every account's document root (<user>:nginx 0750,
# Apache is a member of nginx behind it, <user>:apache on apache_phpfpm).
# Without this, an account could put a link in its own site pointing at
# another account's file (a .env, a config.php, a backup) and download it
# through the web server.
#
#   nginx    /etc/nginx/conf.d/00-reqad-symlinks.conf, http level:
#            disable_symlinks if_not_owner from=$document_root
#            (the document root itself may be a link - Git deployments)
#   Apache   only where it runs as apache (php-fpm templates: apache_phpfpm,
#            nginx_apache_php-fpm; with mod_php, mod_ruid2 already runs each
#            request as its account): the account vhosts' <Directory> loses
#            FollowSymLinks (SymLinksIfOwnerMatch stays) and .htaccess may no
#            longer turn it back on (AllowOverride All Options=...); .htaccess
#            files in the document roots that ask for FollowSymLinks get
#            SymLinksIfOwnerMatch instead, which rewrite rules accept the same
#            way (a .htaccess still asking for it answers 500). Copies of the
#            changed files go to /var/lib/reqad/symlink-protection/<time>/.
#
# Before turning it on, links that would stop working are listed. Runs on
# every update; idempotent.
#
# Usage: setup_symlink_protection.sh [--dry-run|--htaccess|--remove]
#   --htaccess  only fix .htaccess files (the dashboard asks for it when Apache
#               refused one)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=apply
case "${1:-}" in
    --dry-run)  MODE=dry ;;
    --htaccess) MODE=htaccess ;;
    --remove)   MODE=remove ;;
    '') ;;
    *) echo "Usage: $0 [--dry-run|--htaccess|--remove]"; exit 2 ;;
esac

ROOT=/usr/local/reqad
. "$ROOT/scripts/web_caps.sh"
web_caps
NCONF=/etc/nginx/conf.d/00-reqad-symlinks.conf
OPTS='Options=Indexes,MultiViews,SymLinksIfOwnerMatch,IncludesNOEXEC'
BAK=/var/lib/reqad/symlink-protection/$(date +%Y%m%d-%H%M%S)
say() { echo "setup_symlink_protection: $*"; }

backup() {      # keep a copy of $1 once per run
    [ "$MODE" = dry ] && return 0
    local d="$BAK$(dirname "$1")"
    [ -e "$d/$(basename "$1")" ] && return 0
    mkdir -p "$d" && chmod 0700 "$BAK" && cp -p "$1" "$d/"
}

# document roots of the sites: every `root` / DocumentRoot under /home
docroots() {
    grep -h -E '^[[:space:]]*(root|DocumentRoot)[[:space:]]+/home/' /etc/nginx/conf.d/*.conf /etc/httpd/conf.d/*.conf 2>/dev/null \
        | awk '{print $2}' | tr -d '";' | sort -u
}

# links whose owner differs from their target's: they stop being served
mismatched_links() {
    local d l t
    for d in $(docroots); do
        [ -d "$d" ] || continue
        find "$d/" -xdev -type l 2>/dev/null | while read -r l; do
            t=$(readlink -f "$l") || continue
            [ -e "$t" ] || continue
            [ "$(stat -c %u "$l")" = "$(stat -c %u "$t")" ] || echo "$l ($(stat -c %U "$l")) -> $t ($(stat -c %U "$t"))"
        done
    done
}

# Apache counts only where it reads the files as apache
apache_used() {
    [ "$PHP_MODE" = fpm ] && { [ "$WEB_FRONT" = httpd ] || [ "$WEB_BACKEND" = httpd ]; } && command -v httpd >/dev/null 2>&1
}

fix_htaccess() {
    local d f n=0
    for d in $(docroots); do
        [ -d "$d" ] || continue
        while IFS= read -r -d '' f; do
            grep -qiE '^[[:space:]]*Options[[:space:]].*FollowSymLinks' "$f" || continue
            n=$((n + 1))
            if [ "$MODE" = dry ]; then say "would change FollowSymLinks to SymLinksIfOwnerMatch in $f"; continue; fi
            backup "$f"
            # rewrite in place: same inode, owner and mode
            sed -E 's/^([[:space:]]*Options[[:space:]].*)([+-]?)FollowSymLinks/\1\2SymLinksIfOwnerMatch/I' "$f" > "$f.reqad-tmp" \
                && cat "$f.reqad-tmp" > "$f"; rm -f "$f.reqad-tmp"
            say "$f: FollowSymLinks -> SymLinksIfOwnerMatch"
        done < <(find "$d/" -xdev -name .htaccess -type f -print0 2>/dev/null)
    done
    [ "$n" -gt 0 ] || say ".htaccess files: none asks for FollowSymLinks"
}

if [ "$MODE" = htaccess ]; then
    apache_used || { say "Apache does not serve the sites as apache here, nothing to do"; exit 0; }
    fix_htaccess
    exit 0
fi

if [ "$MODE" = remove ]; then
    if [ -f "$NCONF" ]; then
        rm -f "$NCONF"
        nginx -t >/dev/null 2>&1 && systemctl reload nginx
        say "nginx: removed $NCONF"
    fi
    say "Apache vhosts are left as they are"
    exit 0
fi

# ── nginx ────────────────────────────────────────────────────────────────────
if [ "$WEB_FRONT" = nginx ] && command -v nginx >/dev/null 2>&1; then
    m=$(mismatched_links)
    if [ -n "$m" ]; then
        say "these links point at files of another owner and will answer 403/404:"
        echo "$m" | sed 's/^/    /'
    fi
    if [ -f "$NCONF" ]; then
        say "nginx: already on"
    elif [ "$MODE" = dry ]; then
        say "nginx: would write $NCONF"
    else
        cat > "$NCONF" <<'EOF'
# Reqad: a site may only follow a symbolic link when the link and its target
# have the same owner, so one account cannot serve another account's files by
# linking to them. The document root itself may be a link (Git deployments).
# Written by scripts/update/setup_symlink_protection.sh - changes are overwritten.
disable_symlinks if_not_owner from=$document_root;
EOF
        if out=$(nginx -t 2>&1); then
            systemctl reload nginx
            say "nginx: on"
        else
            rm -f "$NCONF"
            say "nginx: the configuration test failed, left off: $(echo "$out" | tail -n2 | tr '\n' ' ')"
        fi
    fi
fi

# ── Apache ───────────────────────────────────────────────────────────────────
if apache_used; then
    changed=()
    for f in /etc/httpd/conf.d/*.conf; do
        [ -f "$f" ] || continue
        grep -qE '^[[:space:]]*DocumentRoot[[:space:]]+"?/home/' "$f" || continue
        if grep -qE '^[[:space:]]*Options[[:space:]].*\bFollowSymLinks\b' "$f" \
           || grep -qE '^[[:space:]]*AllowOverride[[:space:]]+All[[:space:]]*$' "$f"; then
            if [ "$MODE" = dry ]; then say "Apache: would restrict symbolic links in $f"; continue; fi
            backup "$f"
            sed -i -E -e 's/^([[:space:]]*Options[[:space:]].*)[[:space:]]+\+?FollowSymLinks\b/\1/' \
                      -e 's/^([[:space:]]*Options)[[:space:]]*$/\1 SymLinksIfOwnerMatch/' \
                      -e "s/^([[:space:]]*AllowOverride[[:space:]]+All)[[:space:]]*\$/\1 $OPTS/" "$f"
            changed+=("$f")
        fi
    done
    fix_htaccess
    if [ "${#changed[@]}" -gt 0 ]; then
        if out=$(httpd -t 2>&1); then
            systemctl reload httpd 2>/dev/null
            say "Apache: ${#changed[@]} vhost(s) restricted"
        else
            for f in "${changed[@]}"; do cp -p "$BAK$f" "$f"; done
            say "Apache: the configuration test failed, vhosts put back: $(echo "$out" | head -n2 | tr '\n' ' ')"
        fi
    elif [ "$MODE" != dry ]; then
        say "Apache: vhosts already restricted"
    fi
fi
exit 0
