#!/bin/bash
# Check, and with --fix repair, the owners and permissions of everything in a
# hosting account's home - the files the account's sites, mail and cron jobs
# use. Typical causes: files copied in as root (a restore, an upload over
# SFTP as root, an `unzip` run by root), a plugin or script that made files
# world-writable, permissions changed by hand.
#
# What is checked, under /home/<user> (symlinks are never followed, other
# filesystems mounted inside are skipped):
#
#   owner        every file belongs to the account. Files of root are listed
#                apart: often copied in as root by mistake, but sometimes on
#                purpose (published files the site must not change);
#                --keep-root leaves them as they are
#   group        the account's own group, or the web server's (nginx /
#                apache: docroots are <user>:<web> so the web server can read
#                them), or mail inside ~/mail (the mail spool); any other
#                group becomes the account's
#   world-writable  no file or folder anyone on the server can write to
#   setuid/setgid   no setuid or setgid files (setgid FOLDERS are fine:
#                public_html is 2750 so new files get the web server's group)
#   ~/.ssh       0700, authorized_keys 0600 (sshd refuses keys otherwise)
#   secrets      wp-config.php and .env files not readable by everyone
#
# Then, with --fix, the top folders (home 0711, docroots <user>:<web> 0750,
# tmp, logs, the php-fpm pool) are set by harden_account_perms.sh. Nothing
# gains a permission it did not have, except ~/.ssh's owner bits.
#
# --fix first writes /var/lib/reqad/perms-restore-<user>-<time>.sh, which puts
# back the owner, group and mode of every path it changes.
#
# Usage: fix_account_perms.sh <user> [--fix] [--keep-root] [--quiet]
#   (default: only check; exit 0 = nothing wrong, 3 = problems found)
# Also: reqad account perms <user> [--fix]

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

USAGE="usage: $0 <user> [--fix] [--keep-root] [--quiet]"
USER_=''; FIX=''; QUIET=''; KEEPROOT=''
for a in "$@"; do
    case "$a" in
        --fix)       FIX=1 ;;
        --keep-root) KEEPROOT=1 ;;
        --quiet)     QUIET=1 ;;
        -*)          echo "$USAGE"; exit 2 ;;
        *)           [ -z "$USER_" ] && USER_=$a || { echo "$USAGE"; exit 2; } ;;
    esac
done
[[ "$USER_" =~ ^[a-z][a-z0-9]{1,15}$ ]] || { echo "$USAGE"; exit 2; }
U=$USER_
UID_=$(id -u "$U" 2>/dev/null) || { echo "no user $U"; exit 1; }
[ "$UID_" -ge 1000 ] || { echo "$U is not a hosting account"; exit 1; }
H=$(getent passwd "$U" | cut -d: -f6)
[ -n "$H" ] && [ -d "$H" ] && [ "$H" != / ] || { echo "$U has no home folder"; exit 1; }
G=$(id -gn "$U")

INI=/usr/local/reqad/etc/server-software.ini
. /usr/local/reqad/scripts/web_caps.sh
web_caps "$INI"
[ -n "$WEB_FRONT" ] || { WEB_FRONT=nginx; WEB_PHP=nginx; }
WEB=$(web_user "$WEB_FRONT")      # docroot group: the front server's (a backend joins it)

out() { [ -n "$QUIET" ] || echo "$@"; }
TMP=$(mktemp -d /var/lib/reqad/perms-check.XXXXXX) || exit 1
trap 'rm -rf "$TMP"' EXIT

# One list per problem, NUL-separated paths (names can hold anything).
find "$H" -xdev ! -user "$U" ! -user root -print0 > "$TMP/owner" 2>/dev/null
find "$H" -xdev -user root -print0 > "$TMP/root" 2>/dev/null
find "$H" -xdev -user "$U" ! -group "$G" ! -group "$WEB" \
    ! \( -group mail \( -path "$H/mail" -o -path "$H/mail/*" \) \) -print0 > "$TMP/group" 2>/dev/null
find "$H" -xdev ! -type l -perm -0002 -print0 > "$TMP/world" 2>/dev/null
find "$H" -xdev -type f \( -perm -4000 -o -perm -2000 \) -print0 > "$TMP/suid" 2>/dev/null
find "$H" -xdev -type f \( -name wp-config.php -o -name .env \) -perm /0004 -print0 > "$TMP/secret" 2>/dev/null
: > "$TMP/ssh"
if [ -d "$H/.ssh" ] && [ ! -L "$H/.ssh" ]; then
    [ "$(stat -c %a "$H/.ssh")" = 700 ] || printf '%s\0' "$H/.ssh" >> "$TMP/ssh"
    for k in "$H/.ssh/authorized_keys" "$H/.ssh/authorized_keys2"; do
        [ -f "$k" ] && [ ! -L "$k" ] && [ "$(stat -c %a "$k")" != 600 ] && printf '%s\0' "$k" >> "$TMP/ssh"
    done
fi

count() { tr -cd '\0' < "$1" | wc -c; }
show() {    # <file> <label>
    local n; n=$(count "$1")
    [ "$n" -eq 0 ] && return
    out "  $2: $n"
    [ -n "$QUIET" ] && return
    tr '\0' '\n' < "$1" | head -5 | sed "s|^|      |"
    [ "$n" -gt 5 ] && out "      ... and $((n - 5)) more"
}
total=0
[ -n "$KEEPROOT" ] && : > "$TMP/root.kept" && mv "$TMP/root" "$TMP/root.kept" && : > "$TMP/root"
for f in owner root group world suid secret ssh; do total=$((total + $(count "$TMP/$f"))); done

out "Account $U ($H), web server group $WEB:"
if [ "$total" -eq 0 ]; then
    out "  owners and permissions are fine."
else
    show "$TMP/owner"  "owned by another user"
    show "$TMP/root"   "owned by root"
    show "$TMP/group"  "group neither $G nor $WEB"
    show "$TMP/world"  "writable by everyone"
    show "$TMP/suid"   "setuid / setgid files"
    show "$TMP/secret" "wp-config.php / .env readable by everyone"
    show "$TMP/ssh"    "~/.ssh not 0700 / authorized_keys not 0600"
fi

[ -n "$KEEPROOT" ] && [ "$(count "$TMP/root.kept")" -gt 0 ] && out "  owned by root, left as they are (--keep-root): $(count "$TMP/root.kept")"

if [ -z "$FIX" ]; then
    if [ "$total" -gt 0 ]; then
        out "Nothing changed. Run with --fix to repair (reqad account perms $U --fix${KEEPROOT:+ --keep-root})."
        exit 3
    fi
    exit 0
fi

# ---- fix -------------------------------------------------------------------
REST=/var/lib/reqad/perms-restore-$U-$(date +%Y%m%d-%H%M%S).sh
{
    echo "#!/bin/bash"
    echo "# Puts back what fix_account_perms.sh $U changed on $(date '+%F %T')."
} > "$REST"
chmod 700 "$REST"
remember() {    # restore lines for the NUL list in $1
    while IFS= read -r -d '' p; do
        [ -e "$p" ] || [ -L "$p" ] || continue
        printf 'chown -h %q %q\n' "$(stat -c '%U:%G' "$p")" "$p"
        [ -L "$p" ] || printf 'chmod %s %q\n' "$(stat -c '%a' "$p")" "$p"
    done < "$1" >> "$REST"
}
for f in owner root group world suid secret ssh; do remember "$TMP/$f"; done

# owner: the account; the group stays when it is the account's or the web
# server's, otherwise it becomes the account's
while IFS= read -r -d '' p; do
    g=$(stat -c %G "$p")
    if [ "$g" = "$G" ] || [ "$g" = "$WEB" ]; then chown -h "$U" "$p"; else chown -h "$U:$G" "$p"; fi
done < <(cat "$TMP/owner" "$TMP/root")
xargs -0 -r chgrp -h "$G" < "$TMP/group"
xargs -0 -r chmod o-w < "$TMP/world"
xargs -0 -r chmod ug-s < "$TMP/suid"
xargs -0 -r chmod o-rwx < "$TMP/secret"
if [ -d "$H/.ssh" ] && [ ! -L "$H/.ssh" ]; then
    chmod 700 "$H/.ssh"
    for k in "$H/.ssh/authorized_keys" "$H/.ssh/authorized_keys2"; do
        [ -f "$k" ] && [ ! -L "$k" ] && chmod 600 "$k"
    done
fi
out "Fixed $total path(s); to undo: bash $REST"

# the top folders and the pool, by the permissions model
out "Top folders (harden_account_perms.sh):"
if [ -n "$QUIET" ]; then
    bash /usr/local/reqad/scripts/update/harden_account_perms.sh --apply --user="$U" --quiet
else
    bash /usr/local/reqad/scripts/update/harden_account_perms.sh --apply --user="$U" | sed 's/^/  /'
fi
exit 0
