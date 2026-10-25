#!/bin/bash
# Keeps root's mail on the server while it is not forwarded.
#
# Exim delivers mail for root here (router root_mail_log, transport
# root_mail_log_pipe, set up by scripts/update/setup_root_mail_log.sh) when
# /etc/aliases has no root: line, i.e. when Settings > System mail does not
# forward it. Before that, root's mail had nowhere to go: local_delivery runs as
# the recipient, root is on never_users, and every message (cron, lfd, monit,
# sudo) sat in the queue for days until it timed out.
#
# Each message is appended to /var/log/reqad/root-mail with a separator line,
# its From/To/Subject/Date headers and the body. The size is hard-capped: past
# 10 MB the file is copied to root-mail.1 (replacing the older copy) and
# emptied, so the two files never hold more than about 20 MB together. One
# message keeps at most 256 KB.
#
# Runs as reqad (exim refuses root). /var/log/reqad belongs to root, so reqad
# cannot create files there: the setup script creates both files reqad:reqad
# 0600 and this script only writes into them (rotation copies and truncates
# instead of renaming). Mail can contain passwords and other secrets, hence 0600:
# only reqad (the panel) and root can read it.
#
# Always exits 0: a message that cannot be written is dropped rather than left
# in the queue, which is what this replaces. The health check reports a missing
# or unwritable log file.

LOG=/var/log/reqad/root-mail
MAX=10485760      # 10 MB per file, two files
PER=262144        # at most 256 KB of one message

umask 077
export LC_ALL=C

if ! exec 9>>"$LOG" 2>/dev/null; then
    cat >/dev/null
    exit 0
fi
flock -w 60 9 || { cat >/dev/null; exit 0; }

{
    printf '\n==== %s  id %s  from <%s>\n' "$(date '+%Y-%m-%d %H:%M:%S %z')" "${MESSAGE_ID:-?}" "${SENDER:-}"
    # Control characters are dropped (tab and newline kept): an escape sequence
    # in a message must not reach a terminal that cats this file.
    head -c $((PER + 1)) | tr -d '\000-\010\013-\037\177' | awk -v max=$PER -v hdr=1 '
        { n += length($0) + 1 }
        n > max { print "[... the rest of this message was cut off: over 256 KB]"; exit }
        hdr && /^$/ { hdr = 0; print ""; next }
        hdr {
            if ($0 ~ /^[ \t]/) { if (keep) print; next }
            keep = (tolower($0) ~ /^(from|to|cc|subject|date):/)
            if (keep) print
            next
        }
        { print }
    '
    cat >/dev/null
} >&9

if [ "$(stat -c %s "$LOG" 2>/dev/null || echo 0)" -gt $MAX ]; then
    # Truncate even when the copy fails: the cap matters more than the old mail.
    cat "$LOG" > "$LOG.1" 2>/dev/null
    : > "$LOG"
fi
exit 0
