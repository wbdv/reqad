#!/bin/bash
# Reqad webmail auto-login — privileged management of the dovecot master user.
#
# Called from PHP as `sudo -n /usr/local/reqad/scripts/mail/webmail-helper.sh <verb> ...`
# with every argument escapeshellarg'd. The reqad user has NOPASSWD:ALL, so
# argument validation HERE is the only real boundary. This script owns exactly
# one file and never takes a path from the caller.
#
# The matching passdb block in /etc/dovecot/local.conf is NOT written here: it
# goes through apply_mail_config() in PHP, so it is validated with doveconf and
# reverted on failure like every other configuration change.
#
# Verbs
#   enable <hash>   write the master-user file with this password hash
#   disable         remove it
#   status          print "enabled" or "disabled"
#
# Exit 0 on success. On failure, exit non-zero with the reason on stderr.

set -u
export LC_ALL=C

MASTER_FILE="/etc/dovecot/master-users"
MASTER_USER="reqad-master"

die() { echo "$*" >&2; exit 1; }

verb="${1-}"
[ -n "$verb" ] || die "missing verb"
shift || true

case "$verb" in

enable)
    hash="${1-}"
    [ -n "$hash" ] || die "missing password hash"
    # only a crypt(3) hash, and nothing that could add a second passwd-file
    # field or a second line. [:space:] covers the newline: $(printf '\n')
    # would be stripped to the empty string by command substitution, and the
    # resulting `**` pattern would reject every hash.
    case "$hash" in
        *[:[:space:]]* ) die "invalid password hash" ;;
    esac
    printf '%s' "$hash" | grep -qE '^\$6\$[A-Za-z0-9./]{1,16}\$[A-Za-z0-9./]{20,}$' \
        || die "invalid password hash"

    umask 077
    printf '%s:%s\n' "$MASTER_USER" "$hash" > "$MASTER_FILE" || die "could not write $MASTER_FILE"
    chown root:dovecot "$MASTER_FILE" 2>/dev/null || chown root:root "$MASTER_FILE"
    chmod 0640 "$MASTER_FILE"
    echo "enabled"
    ;;

disable)
    rm -f "$MASTER_FILE" || die "could not remove $MASTER_FILE"
    echo "disabled"
    ;;

status)
    [ -s "$MASTER_FILE" ] && echo "enabled" || echo "disabled"
    ;;

*)
    die "unknown verb: $verb"
    ;;
esac
