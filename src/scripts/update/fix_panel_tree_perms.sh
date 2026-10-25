#!/bin/bash
# Keep hosting accounts out of the panel's own tree (/usr/local/reqad).
#
# /usr/local/reqad is the reqad user's home and was created 0755, with the
# panel's secrets inside it world-readable:
#   db/reqad.db          settings: DNS API tokens, SMTP password, remote backup
#                        credentials, csrf secret (and, from 1037, sessions)
#   nginx_auth           the admin password hash (0664)
#   backup/*.tar.gz      full account backups - files, mail and database dumps
#                        of EVERY account (0644, dir 0775)
# so any account (PHP, cron, SSH) could read every other account's backup and
# take over the panel's integrations. Nothing outside reqad and root needs this
# tree: nginx (worker user reqad) and the reqad php-fpm pool own it, every
# cron/helper runs as root, exim's alias pipe runs as reqad.
#
# The top directory becomes 0750 reqad:reqad (it is not owned by the rpm, so an
# update does not reset it), and the secrets are tightened individually as well
# in case the tree is ever opened again. Idempotent.
#
# Usage: fix_panel_tree_perms.sh [--dry-run]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DRY=0
[ "$1" = "--dry-run" ] && DRY=1

ROOT=/usr/local/reqad
[ -d "$ROOT" ] || exit 0

CHANGED=0
# set_mode <octal> <path>: chmod only when different, report what changed
set_mode() {
    local want=$1 path=$2 have
    [ -e "$path" ] || return 0
    have=$(stat -c %a "$path")
    [ "$have" = "$want" ] && return 0
    CHANGED=1
    if [ "$DRY" = 1 ]; then
        echo "would chmod $have -> $want $path"
    else
        chmod "$want" "$path" && echo "chmod $have -> $want $path"
    fi
}

set_mode 750 "$ROOT"
set_mode 600 "$ROOT/nginx_auth"
set_mode 640 "$ROOT/public_html/defines.php"
set_mode 750 "$ROOT/db"
for f in "$ROOT"/db/*.db "$ROOT"/db/*.db-journal "$ROOT"/db/*.db-wal "$ROOT"/db/*.db-shm; do
    [ -f "$f" ] && set_mode 600 "$f"
done
set_mode 750 "$ROOT/log"
if [ -d "$ROOT/backup" ]; then
    set_mode 700 "$ROOT/backup"
    while IFS= read -r -d '' d; do set_mode 700 "$d"; done < <(find "$ROOT/backup" -mindepth 1 -type d -print0)
    while IFS= read -r -d '' f; do set_mode 600 "$f"; done < <(find "$ROOT/backup" -type f -print0)
fi

# Members of the reqad group can still traverse a 0750 tree. Say so: on a
# hosting server nobody but reqad itself should be in it.
MEMBERS=$(getent group reqad | cut -d: -f4)
[ -n "$MEMBERS" ] && echo "fix_panel_tree_perms: NOTE - group reqad has members ($MEMBERS); they can still enter $ROOT. Remove them unless intended: gpasswd -d <user> reqad"

[ "$CHANGED" = 0 ] && echo "fix_panel_tree_perms: already correct"
exit 0
