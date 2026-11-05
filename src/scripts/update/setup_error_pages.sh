#!/bin/bash
# Reqad error pages for every hosted site -- replaces the stock nginx/Apache
# pages ("403 Forbidden / nginx", Apache's signature line) with plain branded-
# free pages that do not name the server software.
#
# The pages live in /usr/local/reqad/scripts/templates/error-pages/ and are
# served from there, so an RPM update refreshes them without re-running this.
#
# nginx
#   /etc/nginx/reqad-error-pages.conf holds error_page + an internal location;
#   each vhost includes it (server level, after its `index` line). 403 is
#   answered as 404 so a blocked file looks exactly like a missing one.
#   The vhost template set `fastcgi_intercept_errors on`, which with an
#   error_page in place would swap WordPress's own themed 404s and maintenance
#   503 for the generic page -- so it is turned off here: the generic pages are
#   only for errors nginx itself produces (missing static files, denied paths,
#   PHP-FPM down).
#   Vhosts that already have an error_page of their own are left alone.
#
# Home page of a site with no files (no index page): empty.html ("This
#   website is coming soon") instead of the 404 page (nginx) or the
#   distribution's "HTTP Server Test Page" (Apache). Only for the home page
#   itself; other missing paths keep the 404 page.
#
# Apache
#   /etc/httpd/conf.d/00-reqad-error-pages.conf: ErrorDocument + Alias,
#   ServerTokens Prod, ServerSignature Off. Apache cannot change the status
#   code of an ErrorDocument, so 403 keeps its own page there (and the empty
#   site's home page answers 403, as before).
#   The httpd package's conf.d/welcome.conf (its test page on `/`, plus
#   /.noindex.html and /poweredby.png on every site) loads after our file and
#   would win, so its lines are commented out - the way the file itself says
#   to turn it off; an rpm update keeps the edited file (noreplace).
#
# Idempotent. Changed vhosts are backed up to /var/lib/reqad/vhost-backups/;
# if `nginx -t` (or `httpd -t`) fails, everything is put back.
#
# Usage: setup_error_pages.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

SRC=/usr/local/reqad/scripts/templates/error-pages
[ -f "$SRC/404.html" ] || { echo "  error pages: $SRC missing, skipping"; exit 0; }
# Served from a copy: /usr/local/reqad is 0750 (fix_panel_tree_perms.sh), so
# the web server cannot read the pages where they ship and answered every
# error with 403. Refreshed on every run.
PAGES=/usr/share/reqad/error-pages
if [ "$DRY" = "0" ]; then
    install -d -m 0755 -o root -g root /usr/share/reqad "$PAGES"
    install -m 0644 -o root -g root "$SRC"/*.html "$PAGES"/
fi

# ---------------------------------------------------------------- nginx ----
if command -v nginx >/dev/null 2>&1 && [ -d /etc/nginx/conf.d ]; then
    SNIPPET=/etc/nginx/reqad-error-pages.conf
    CONF_DIR=/etc/nginx/conf.d
    BACKUP_DIR=/var/lib/reqad/vhost-backups/error-pages-$(date +%Y%m%d%H%M%S)

    NEW_SNIPPET=$(cat <<EOF
# Reqad error pages -- managed by scripts/update/setup_error_pages.sh.
# Included at server level by each vhost. Only errors nginx produces itself
# get these pages (fastcgi_intercept_errors is off), so WordPress keeps its
# own 404 and maintenance pages. 403 is answered as 404: a blocked file looks
# the same as a missing one.
error_page 403 =404 /reqad-error/404.html;
error_page 404 /reqad-error/404.html;
error_page 500 /reqad-error/500.html;
error_page 502 /reqad-error/502.html;
error_page 503 /reqad-error/503.html;
error_page 504 /reqad-error/504.html;

location ^~ /reqad-error/ {
    internal;
    alias $PAGES/;
    # The home page of a site with no files yet: "coming soon" instead of
    # the 404 page. \$request_uri is still the visitor's own request here.
    # 'last' (not 'break'): alias cannot serve a URI rewritten in place.
    if (\$request_uri ~ "^/+(\?.*)?\$") {
        rewrite ^/reqad-error/40[34]\.html\$ /reqad-error/empty.html last;
    }
}
EOF
)
    OLD_SNIPPET=$(cat "$SNIPPET" 2>/dev/null)

    CHANGED=$(python3 - "$CONF_DIR" "$BACKUP_DIR" "$DRY" <<'PY'
import os, re, sys, shutil

conf_dir, backup_dir, dry = sys.argv[1], sys.argv[2], sys.argv[3] == '1'
INCLUDE = 'include /etc/nginx/reqad-error-pages.conf;'

def fix(text):
    if 'error_page' in text:            # has its own error pages: not ours to change
        return text
    out, depth, done_block = [], 0, False
    for line in text.split('\n'):
        code = line.split('#', 1)[0]
        out.append(line)
        # first `index` directive directly inside a server block
        if (depth == 1 and not done_block and re.match(r'\s*index\s', code)
                and INCLUDE not in text):
            ind = line[:len(line) - len(line.lstrip())]
            out.append(ind + INCLUDE)
            done_block = True
        depth += code.count('{') - code.count('}')
        if depth == 0:
            done_block = False          # next server block gets its own
    new = '\n'.join(out)
    # generic pages must not replace the application's own error responses
    new = re.sub(r'^(\s*fastcgi_intercept_errors\s+)on(\s*;)', r'\1off\2', new, flags=re.M)
    return new

changed = []
for name in sorted(os.listdir(conf_dir)):
    path = os.path.join(conf_dir, name)
    if not name.endswith('.conf') or not os.path.isfile(path):
        continue
    orig = open(path).read()
    new = fix(orig)
    if new == orig:
        continue
    changed.append(path)
    print(path, file=sys.stderr)
    if dry:
        continue
    os.makedirs(backup_dir, mode=0o700, exist_ok=True)
    shutil.copy2(path, os.path.join(backup_dir, name))
    st = os.stat(path)
    with open(path, 'w') as f:
        f.write(new)
    os.chown(path, st.st_uid, st.st_gid)
    os.chmod(path, st.st_mode & 0o7777)
print('\n'.join(changed))
PY
)

    if [ "$DRY" = "1" ]; then
        [ "$NEW_SNIPPET" != "$OLD_SNIPPET" ] && echo "  error pages: would write $SNIPPET"
        echo "  error pages: would update $(echo -n "$CHANGED" | grep -c .) nginx vhost(s)"
    else
        [ "$NEW_SNIPPET" != "$OLD_SNIPPET" ] && printf '%s\n' "$NEW_SNIPPET" > "$SNIPPET"
        if nginx -t >/dev/null 2>&1; then
            if [ -n "$CHANGED" ] || [ "$NEW_SNIPPET" != "$OLD_SNIPPET" ]; then
                systemctl reload nginx
                echo "  error pages: nginx updated ($(echo -n "$CHANGED" | grep -c .) vhost(s))"
            else
                echo "  error pages: nginx already up to date"
            fi
        else
            echo "  error pages: nginx -t failed, restoring"
            for f in $CHANGED; do cp -a "$BACKUP_DIR/$(basename "$f")" "$f"; done
            if [ -n "$OLD_SNIPPET" ]; then printf '%s\n' "$OLD_SNIPPET" > "$SNIPPET"; fi
            nginx -t
        fi
    fi
fi

# --------------------------------------------------------------- Apache ----
if [ -d /etc/httpd/conf.d ] && command -v httpd >/dev/null 2>&1; then
    ACONF=/etc/httpd/conf.d/00-reqad-error-pages.conf
    NEW_A=$(cat <<EOF
# Reqad error pages -- managed by scripts/update/setup_error_pages.sh.
# Server-wide: every virtual host inherits these unless it sets its own.
ServerTokens Prod
ServerSignature Off

Alias /reqad-error/ $PAGES/
<Directory $PAGES>
    Options None
    AllowOverride None
    Require all granted
</Directory>

ErrorDocument 403 /reqad-error/403.html
ErrorDocument 404 /reqad-error/404.html
ErrorDocument 500 /reqad-error/500.html
ErrorDocument 502 /reqad-error/502.html
ErrorDocument 503 /reqad-error/503.html
ErrorDocument 504 /reqad-error/504.html

# The home page of a site with no files yet (no index page): "coming soon".
<LocationMatch "^/+\$">
    Options -Indexes
    ErrorDocument 403 /reqad-error/empty.html
</LocationMatch>
EOF
)
    OLD_A=$(cat "$ACONF" 2>/dev/null)

    # The distribution's test page (see the header): comment out its lines.
    WELCOME=/etc/httpd/conf.d/welcome.conf
    WELCOME_ON=0
    [ -f "$WELCOME" ] && grep -qEv '^[[:space:]]*(#|$)' "$WELCOME" && WELCOME_ON=1

    if [ "$NEW_A" = "$OLD_A" ] && [ "$WELCOME_ON" = "0" ]; then
        echo "  error pages: apache already up to date"
    elif [ "$DRY" = "1" ]; then
        [ "$NEW_A" != "$OLD_A" ] && echo "  error pages: would write $ACONF"
        [ "$WELCOME_ON" = "1" ] && echo "  error pages: would turn off $WELCOME"
    else
        WELCOME_BAK=
        if [ "$WELCOME_ON" = "1" ]; then
            WELCOME_BAK=$(mktemp)
            cp -a "$WELCOME" "$WELCOME_BAK"
            { echo "# Turned off by Reqad (scripts/update/setup_error_pages.sh): a site with"
              echo "# no files shows Reqad's \"coming soon\" page instead of this test page."
              sed -E 's/^([[:space:]]*[^#[:space:]])/#\1/' "$WELCOME_BAK"; } > "$WELCOME"
        fi
        printf '%s\n' "$NEW_A" > "$ACONF"
        if httpd -t >/dev/null 2>&1; then
            systemctl is-active --quiet httpd && systemctl reload httpd
            echo "  error pages: apache updated"
        else
            echo "  error pages: httpd -t failed, restoring"
            if [ -n "$OLD_A" ]; then printf '%s\n' "$OLD_A" > "$ACONF"; else rm -f "$ACONF"; fi
            [ -n "$WELCOME_BAK" ] && cp -a "$WELCOME_BAK" "$WELCOME"
            httpd -t
        fi
        [ -n "$WELCOME_BAK" ] && rm -f "$WELCOME_BAK"
    fi
fi
exit 0
