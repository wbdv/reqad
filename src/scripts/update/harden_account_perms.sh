#!/bin/bash
# Stop hosting accounts from reading each other's files.
#
# Until now an account's public_html was <user>:<user> 0775 (some 0777): the
# web server read it as "other", which also let every OTHER account - its SSH
# session, its cron jobs - read it, wp-config.php included, and on php-fpm
# every pool ran with the web server's group. The model this applies:
#
#   docroots (public_html, addon domain roots)  <user>:<web> , other = 0
#   tmp, logs (sessions, uploads, PHP log)      other = 0
#   php-fpm pools                               group = <user> (was <web>)
#
# <web> is nginx or apache, from etc/server-software.ini. The web server keeps
# exactly the access it had - its old "other" bits become the group bits - and
# everyone else loses it. On php-fpm and nginx the web server never needs to
# write, so the group gets no write bit. On Apache with mod_php, PHP itself is
# the apache user, so there the group keeps write where "other" had it, and
# tmp/ and logs/ (sessions, uploads, the PHP error log) go to group apache too.
# The pool group change is what makes the docroot group safe: a pool running
# as group nginx could otherwise read every docroot through it.
#
# Nothing below the top directories changes, nothing gains a permission, and a
# directory locked to mode 000 (a disabled site) is left alone. Symlinks are
# never followed.
#
# --apply writes a restore script first (/var/lib/reqad/perms-restore-<time>.sh
# puts back every owner, group, mode and pool file), checks each php-fpm config
# before reloading it, and compares every site's HTTP status before and after.
#
# Usage: harden_account_perms.sh [--dry-run] [--apply] [--user=NAME] [--quiet]
#        (default: --dry-run - shows what would change, changes nothing)
# The panel runs `--apply --user=NAME --quiet` after an Apache account changes
# between mod_php and php-fpm, since mod_php needs tmp/ and logs/ for apache.

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

MODE=dry
ONLY=''
for a in "$@"; do
    case "$a" in
        --dry-run) MODE=dry ;;
        --apply)   MODE=apply ;;
        --user=*)  ONLY="${a#*=}" ;;
        --quiet)   QUIET=1 ;;
        *) echo "usage: $0 [--dry-run] [--apply] [--user=NAME] [--quiet]"; exit 2 ;;
    esac
done

DB=/usr/local/reqad/db/reqad.db
INI=/usr/local/reqad/etc/server-software.ini
SQL="/usr/bin/sqlite3 -init /dev/null -batch -noheader -list"
[ -f "$DB" ] || { echo "no panel database ($DB)"; exit 1; }

. /usr/local/reqad/scripts/web_caps.sh
web_caps "$INI"
[ -n "$WEB_FRONT" ] || { WEB_FRONT=nginx; WEB_PHP=nginx; }   # unknown template: as nginx
# group of docroots and pool sockets: the front server's, which serves the
# static files; a backend (Apache behind nginx) joins that group
WEB=$(web_user "$WEB_FRONT")
VHOSTS=$(web_vhost_dir "$WEB_FRONT")
getent group "$WEB" >/dev/null || { echo "web server group '$WEB' does not exist"; exit 1; }

STAMP=$(date +%Y%m%d-%H%M%S)
RESTORE=/var/lib/reqad/perms-restore-$STAMP.sh
SAVE=/var/lib/reqad/perms-restore-$STAMP
changes=0; pools=0; skipped=0
declare -A RELOAD=()
declare -A DONE=()

QUIET=${QUIET:-}
out() { [ -n "$QUIET" ] || echo "$@"; }

# the mode digits of a path, as 4 octal digits (special bits first)
mode_of() { printf '%04o' "0$(stat -c '%a' "$1")"; }

restore_line() {    # remember how $1 is now, before it changes
    [ "$MODE" = apply ] || return
    printf 'chown -h %q %q && chmod %s %q\n' "$(stat -c '%U:%G' "$1")" "$1" "$(stat -c '%a' "$1")" "$1" >> "$RESTORE"
}

# harden <path> <user> <web-needs-it: yes|no> <web-may-write: yes|no> [group bits]
# Without [group bits] the web server's group gets what it had as "other";
# with it (tmp/ and logs/ on mod_php: 7) exactly that.
harden() {
    local p="$1" u="$2" need="$3" write="$4" fixed="$5"
    [ -n "${DONE[$p]}" ] && return
    DONE[$p]=1
    if [ -L "$p" ]; then out "    $p  symlink - skipped"; skipped=$((skipped + 1)); return; fi
    [ -d "$p" ] || return
    local m own grp cur
    m=$(mode_of "$p"); own=$(stat -c '%U' "$p"); grp=$(stat -c '%G' "$p")
    cur="${m#0} $own:$grp"
    local s=$((8#${m:0:1})) o=$((8#${m:1:1})) g=$((8#${m:2:1})) t=$((8#${m:3:1}))
    if [ "$o" -eq 0 ]; then
        out "    $p  $cur  locked (no owner access) - left alone"; skipped=$((skipped + 1)); return
    fi
    if [ "$own" != "$u" ]; then
        out "    $p  $cur  owned by $own, not $u - left alone"; skipped=$((skipped + 1)); return
    fi
    local ng="$grp" gg="$g"
    if [ "$need" = yes ]; then
        if [ -n "$fixed" ]; then
            ng="$WEB"; gg=$fixed
        elif [ "$grp" != "$WEB" ]; then
            ng="$WEB"
            gg=$t                                   # the web server keeps what it had as "other"
            [ "$write" = yes ] || gg=$((gg & 5))    # ... minus write, unless it is PHP itself
            [ "$gg" -eq 0 ] && gg=5                 # a docroot it could not even enter: let it read
        fi
    fi
    local nm; nm=$(printf '%o%o%o%o' "$s" "$o" "$gg" 0)
    local new="${nm#0} $own:$ng"
    if [ "$new" = "$cur" ]; then
        out "    $p  $cur  ok"
        return
    fi
    out "    $p  $cur  ->  $new"
    changes=$((changes + 1))
    if [ "$MODE" = apply ]; then
        restore_line "$p"
        [ "$ng" != "$grp" ] && chgrp -h "$ng" "$p"
        chmod "$nm" "$p"
    fi
}

# fix_pool <file> : group = <web> -> group = <user>
fix_pool() {
    local f="$1" u g
    u=$(sed -n 's/^[[:space:]]*user[[:space:]]*=[[:space:]]*\([^[:space:];]*\).*/\1/p' "$f" | head -1)
    g=$(sed -n 's/^[[:space:]]*group[[:space:]]*=[[:space:]]*\([^[:space:];]*\).*/\1/p' "$f" | head -1)
    [ -n "$u" ] || return
    [ "$g" = nginx ] || [ "$g" = apache ] || return
    local uid; uid=$(id -u "$u" 2>/dev/null) || { out "    pool $f  user $u does not exist - left alone"; skipped=$((skipped + 1)); return; }
    [ "$uid" -ge 1000 ] || return
    [ -z "$ONLY" ] || [ "$u" = "$ONLY" ] || return
    local ug; ug=$(id -gn "$u")
    out "    pool $f  group $g  ->  $ug   (user $u)"
    pools=$((pools + 1))
    local svc
    case "$f" in
        /etc/opt/remi/php*/php-fpm.d/*) svc="$(echo "$f" | sed 's|/etc/opt/remi/\(php[0-9]*\)/.*|\1|')-php-fpm" ;;
        *) svc=php-fpm ;;
    esac
    RELOAD[$svc]=1
    if [ "$MODE" = apply ]; then
        mkdir -p "$SAVE"; cp -a "$f" "$SAVE/$(echo "$f" | tr / _)"
        printf 'cp -a %q %q\n' "$SAVE/$(echo "$f" | tr / _)" "$f" >> "$RESTORE"
        sed -i "s/^\([[:space:]]*group[[:space:]]*=[[:space:]]*\)$g\b/\1$ug/" "$f"
    fi
}

# the handler of a domain: fpm | mod_php | off
handler_of() {
    local v="$VHOSTS/$1.conf" p
    p="$(web_vhost_dir "$WEB_PHP")/$1.conf"     # the vhost with the PHP handler (the backend's, if any)
    if grep -q '# BEGIN reqad-php-disabled' "$v" "$p" 2>/dev/null; then echo off; return; fi
    if [ "$PHP_MODE" = modphp ] && ! grep -q 'proxy:unix:' "$v" 2>/dev/null; then echo mod_php; return; fi
    echo fpm
}

site_codes() {      # "domain status" per site, for the before/after check
    local d
    for d in $SITE_LIST; do
        printf '%s %s\n' "$d" "$(curl -sk -o /dev/null -m 15 -w '%{http_code}' --resolve "$d:443:127.0.0.1" "https://$d/")"
    done
}

out "Web server: $WEB ($TEMPLATE)$( [ "$MODE" = dry ] && echo ' - DRY RUN, nothing is changed')"
if [ "$MODE" = apply ]; then
    mkdir -p /var/lib/reqad
    printf '#!/bin/bash\n# Undo harden_account_perms.sh run of %s\n' "$STAMP" > "$RESTORE"
    chmod 0700 "$RESTORE"
fi

# every site this run touches, for the before/after HTTP check
SITE_LIST=''
while IFS='|' read -r user domain; do
    [ -n "$user" ] || continue
    [ -z "$ONLY" ] || [ "$user" = "$ONLY" ] || continue
    SITE_LIST="$SITE_LIST $domain"
    for v in $(grep -l "Additional domain of account $user " "$VHOSTS"/*.conf 2>/dev/null); do
        SITE_LIST="$SITE_LIST $(basename "$v" .conf)"
    done
done < <($SQL "$DB" "SELECT user, domain FROM accounts ORDER BY user")
[ "$MODE" = apply ] && BEFORE=$(site_codes)

while IFS='|' read -r user domain; do
    [ -n "$user" ] || continue
    [ -z "$ONLY" ] || [ "$user" = "$ONLY" ] || continue
    id "$user" >/dev/null 2>&1 || { out "$user ($domain): no such system user - skipped"; continue; }
    h=$(handler_of "$domain")
    out ""
    out "$user ($domain, $WEB + $( [ "$h" = mod_php ] && echo mod_php || ([ "$h" = off ] && echo 'PHP off' || echo php-fpm)))"
    mp=no; [ "$h" = mod_php ] && mp=yes
    harden "/home/$user/public_html" "$user" yes "$mp"
    # addon domains of this account: their own document roots
    for v in $(grep -l "Additional domain of account $user " "$VHOSTS"/*.conf 2>/dev/null); do
        ad=$(basename "$v" .conf)
        root=$(sed -n 's/^[[:space:]]*\(root\|DocumentRoot\)[[:space:]]\+"\{0,1\}\([^";]*\)"\{0,1\};\{0,1\}.*/\2/p' "$v" | head -1)
        ah=$(handler_of "$ad")
        [ -n "$root" ] && harden "$root" "$user" yes "$( [ "$ah" = mod_php ] && echo yes || echo no)"
        [ "$ah" = mod_php ] && mp=yes
    done
    # sessions, uploads and the PHP error log: apache needs them on mod_php
    if [ "$mp" = yes ]; then
        harden "/home/$user/tmp"  "$user" yes yes 7
        harden "/home/$user/logs" "$user" yes yes 7
    else
        harden "/home/$user/tmp"  "$user" no no
        harden "/home/$user/logs" "$user" no no
    fi
done < <($SQL "$DB" "SELECT user, domain FROM accounts ORDER BY user")

out ""
out "php-fpm pools"
for f in /etc/php-fpm.d/*.conf /etc/opt/remi/*/php-fpm.d/*.conf; do
    [ -f "$f" ] && fix_pool "$f"
done
[ "$pools" -eq 0 ] && out "    nothing to change"

out ""
if [ "$MODE" = dry ]; then
    out "Would change $changes director$( [ "$changes" = 1 ] && echo y || echo ies) and $pools pool$( [ "$pools" = 1 ] || echo s); $skipped left alone."
    [ "${#RELOAD[@]}" -gt 0 ] && out "Would reload: ${!RELOAD[*]}"
    out "Run with --apply to make the changes."
    exit 0
fi

# --apply: the directories are changed above; pools need a config test, then a reload
for svc in "${!RELOAD[@]}"; do
    case "$svc" in
        php-fpm) bin=/usr/sbin/php-fpm ;;
        *)       bin=/opt/remi/${svc%-php-fpm}/root/usr/sbin/php-fpm ;;
    esac
    if [ -x "$bin" ] && ! "$bin" -t >/dev/null 2>&1; then
        out "php-fpm config test FAILED for $svc - putting the pool files back"
        bash "$RESTORE"
        exit 1
    fi
    systemctl reload "$svc" 2>/dev/null || systemctl restart "$svc"
done
reloaded="${!RELOAD[*]}"
out "Changed $changes director$( [ "$changes" = 1 ] && echo y || echo ies) and $pools pool$( [ "$pools" = 1 ] || echo s); reloaded: ${reloaded:-none}."
sleep 2
AFTER=$(site_codes)
diff_out=$(diff <(echo "$BEFORE") <(echo "$AFTER") | sed -n 's/^> //p')
if [ -n "$diff_out" ]; then
    out ""
    out "Sites that answer differently now (before -> after):"
    while read -r d code; do
        out "    $d: $(echo "$BEFORE" | awk -v d="$d" '$1==d {print $2}') -> $code"
    done <<< "$diff_out"
    out "Check them; to undo everything: bash $RESTORE"
else
    out "Every site answers as before ($(echo "$AFTER" | wc -w | awk '{print $1/2}') checked)."
    out "Undo with: bash $RESTORE"
fi
