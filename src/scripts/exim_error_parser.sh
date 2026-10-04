#!/bin/bash
#
# exim_error_parser.sh -- emit exim's permanent delivery failures from main.log
#
# A separate script only so the reading is done under sudo: the panel user
# cannot open /var/log/exim/main.log directly. Under cron this runs as root
# already and the sudo is a no-op.
#
# Lines are passed through untouched; smtp_error_parse() in modules/functions.php
# does the parsing. The pattern matches exim's own line layout -- timestamp,
# message id, then the "**" (permanent failure) marker -- rather than grepping
# for a bare asterisk, which also hit subjects in the "<=" arrival lines.
#
# Reqad -- https://www.reqad.com/

set -u
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

LOG=${1:-/var/log/exim/main.log}

sudo -n grep -aE '^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2} [^ ]+ \*\* ' "$LOG" 2>/dev/null
exit 0
