#!/bin/bash
# CPU, memory and process limits for every hosting account's SSH, cron and
# web-terminal sessions, plus the hit log. The work is done by
# scripts/session_limits.sh (see there); accounts with their own limits or
# "no limit" are left alone.
#
# Usage: setup_session_limits.sh [--dry-run|--apply|--remove]   (default: --dry-run)
exec /usr/local/reqad/scripts/session_limits.sh setup "$@"
