#!/bin/bash
# Put the https vhost certbot wrote to <site>-le-ssl.conf back into the site's
# own <site>.conf (Apache).
#
# When certbot's Apache plugin finds no :443 vhost for a name, it copies the
# :80 vhost into a new file <site>-le-ssl.conf and installs the certificate
# there. Reqad keeps a site's http and https vhosts in ONE file (adddomain
# --ssl and the account templates do), and everything that reads or edits a
# site's vhost - its certificate on the SSL pages, suspension, redirects, PHP
# settings, extra IP addresses, deleting the site - opens only <site>.conf.
# With the https part elsewhere, those changes missed https.
#
# For each <site>-le-ssl.conf with a matching <site>.conf that has no :443
# vhost of its own, the file's contents are appended to <site>.conf and the
# -le-ssl file is moved out of conf.d. Apache reads conf.d in name order and
# <site>-le-ssl.conf sorts right before <site>.conf, so the vhosts load in the
# same order as before: nothing changes in what is served. The mail client
# autoconfig lines setup_autoconfig.sh once wrote with "-le-ssl" in the file
# name are corrected on the way.
#
# Each site is tested on its own (httpd -t) and put back when the test fails;
# Apache is reloaded once at the end. Backups: /var/lib/reqad/vhost-backups/.
# Safe to re-run: with nothing to merge it changes nothing.
#
# Usage: merge_le_ssl_vhosts.sh [--dry-run] [--domain=<site>] [--quiet]
#   --domain  only that site (the panel calls it so after running certbot)
#   --quiet   print only problems

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
QUIET=0
ONLY=''
for a in "$@"; do
    case "$a" in
        --dry-run)  DRY=1 ;;
        --quiet)    QUIET=1 ;;
        --domain=*) ONLY="${a#--domain=}" ;;
        *) echo "Unknown option: $a" >&2; exit 2 ;;
    esac
done
if [ -n "$ONLY" ] && ! [[ "$ONLY" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then
    echo "Invalid --domain: $ONLY" >&2
    exit 2
fi

CONF_DIR=/etc/httpd/conf.d
BACKUP_DIR=/var/lib/reqad/vhost-backups/le-ssl-merge-$(date +%Y%m%d%H%M%S)

[ -d "$CONF_DIR" ] || exit 0
command -v httpd >/dev/null 2>&1 || exit 0

say()  { [ "$QUIET" -eq 1 ] || echo "  $*"; }
warn() { echo "  $*" >&2; }

if [ -n "$ONLY" ]; then
    FILES=("$CONF_DIR/${ONLY}-le-ssl.conf")
else
    FILES=("$CONF_DIR"/*-le-ssl.conf)
fi

# what to do with each file; nothing is changed in this pass
TODO=()
for le in "${FILES[@]}"; do
    [ -f "$le" ] || continue
    site="$(basename "$le" -le-ssl.conf)"
    main="$CONF_DIR/${site}.conf"
    if [ ! -f "$main" ]; then
        warn "${site}: $(basename "$le") has no ${site}.conf next to it (site removed?) - left alone"
        continue
    fi
    if grep -qiE '^[[:space:]]*<VirtualHost[^>]*:443[[:space:]]*>' "$main"; then
        warn "${site}: both ${site}.conf and $(basename "$le") hold an https vhost - left alone, merge them by hand"
        continue
    fi
    if grep -iE '^[[:space:]]*<VirtualHost' "$le" | grep -qivE ':443[[:space:]]*>'; then
        warn "${site}: $(basename "$le") holds a vhost that is not on port 443 - left alone"
        continue
    fi
    # marker blocks the panel keeps in every vhost of the file: tell when the
    # https copy (made when the certificate was issued) lacks one http has now
    missing=''
    for m in $(grep -oE '# BEGIN reqad-[a-z0-9-]+' "$main" | awk '{print $3}' | sort -u); do
        grep -q "# BEGIN ${m}\$" "$le" || missing="$missing $m"
    done
    [ -n "$missing" ] && warn "${site}: the https vhost lacks${missing}; switch that setting off and on again in the panel after the merge"
    TODO+=("$site")
done

if [ "${#TODO[@]}" -eq 0 ]; then
    [ -n "$ONLY" ] || say "le-ssl vhosts: nothing to merge"
    exit 0
fi

if [ "$DRY" -eq 1 ]; then
    for s in "${TODO[@]}"; do
        echo "  would merge ${s}-le-ssl.conf into ${s}.conf"
    done
    exit 0
fi

if ! httpd -t >/dev/null 2>&1; then
    warn "le-ssl vhosts: httpd -t fails before any change, nothing merged:"
    httpd -t 2>&1 | sed 's/^/    /' >&2
    exit 1
fi

mkdir -p "$BACKUP_DIR" && chmod 0700 "$BACKUP_DIR" || exit 1

merged=0
failed=0
for s in "${TODO[@]}"; do
    main="$CONF_DIR/${s}.conf"
    le="$CONF_DIR/${s}-le-ssl.conf"
    cp -a "$main" "$BACKUP_DIR/${s}.conf" || { failed=$((failed + 1)); continue; }
    cp -a "$le" "$BACKUP_DIR/${s}-le-ssl.conf" || { failed=$((failed + 1)); continue; }

    # writing into the existing file keeps its owner, mode and SELinux label
    {
        sed -e :a -e '/^\n*$/{$d;N;ba' -e '}' "$BACKUP_DIR/${s}.conf"
        echo
        sed "s#/var/lib/reqad/autoconfig/${s//./\\.}-le-ssl\\.#/var/lib/reqad/autoconfig/${s}.#g" "$BACKUP_DIR/${s}-le-ssl.conf"
    } > "$main.reqad-merge" && cat "$main.reqad-merge" > "$main"
    rm -f "$main.reqad-merge"
    rm -f "$le"

    if httpd -t >/dev/null 2>&1; then
        say "merged ${s}-le-ssl.conf into ${s}.conf"
        merged=$((merged + 1))
    else
        warn "${s}: httpd -t failed after the merge, put back:"
        httpd -t 2>&1 | sed 's/^/    /' >&2
        cat "$BACKUP_DIR/${s}.conf" > "$main"
        cp -a "$BACKUP_DIR/${s}-le-ssl.conf" "$le"
        failed=$((failed + 1))
    fi
done

if [ "$merged" -gt 0 ]; then
    systemctl is-active --quiet httpd && systemctl reload httpd
    say "le-ssl vhosts: merged $merged site(s), backups in $BACKUP_DIR"
fi
[ "$failed" -eq 0 ]
