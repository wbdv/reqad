#!/bin/bash
# Reqad spam filters - the single privileged entry point for SpamAssassin
# per-account preference files.
#
# Called from PHP as `sudo -n /usr/local/reqad/scripts/spam-filters/sa-helper.sh <verb> ...`
# with every argument escapeshellarg'd. The reqad user has NOPASSWD:ALL, so
# argument validation HERE is the only real boundary - the account name is never
# pasted into a path, the home directory is taken from the passwd database and
# must sit under /home.
#
# Which file, and why this one: exim's spam_check transport runs
#   spamc -u "${lookup{$domain_data}lsearch* {/etc/exim/userdomains}{$value}}"
# so SpamAssassin is always asked for the HOSTING ACCOUNT's preferences, and
# spamd (started without --virtual-config-dir) setuids to that account and reads
# ~/.spamassassin/user_prefs. That is the same path cPanel uses, which is what
# makes the transfer-tool import a straight copy rather than a conversion.
#
# File content is read from STDIN, never from an argument: it is multi-line.
#
# Verbs
#   get     <account>            print user_prefs (missing file = empty, not an error)
#   put     <account>            < prefs
#   delete  <account>
#   get-all                      length-prefixed dump of every account's prefs
#
# Exit 0 on success. On failure, exit non-zero with the reason on stderr.

set -u
export LC_ALL=C

SA_DIR=".spamassassin"
SA_FILE="user_prefs"
HOME_ROOT="/home"

die() { echo "$*" >&2; exit 1; }

# ── validation ───────────────────────────────────────────────────────────────

valid_account() {
    case "$1" in
        *[!a-z0-9_-]* | -* | '' ) return 1 ;;
    esac
    [ ${#1} -le 32 ] || return 1
}

# Echo the account's .spamassassin directory. The path comes from getent, not
# from the argument, so a name that somehow passed validation still cannot point
# anywhere but that account's own home - and only under /home.
resolve_dir() {
    local acct="$1" home
    valid_account "$acct" || die "invalid account name: $acct"
    home=$(getent passwd "$acct" | cut -d: -f6)
    [ -n "$home" ] || die "no such system account: $acct"
    case "$home" in
        "$HOME_ROOT"/*) : ;;
        *) die "account $acct does not live under $HOME_ROOT (home is $home)" ;;
    esac
    case "$home" in *..*) die "refusing home path with '..': $home" ;; esac
    [ -d "$home" ] || die "home directory does not exist: $home"
    printf '%s/%s' "$home" "$SA_DIR"
}

# ── verbs ────────────────────────────────────────────────────────────────────

sa_get() {
    local dir; dir=$(resolve_dir "$1") || exit 1
    [ -f "$dir/$SA_FILE" ] || exit 0      # no prefs yet is a no-op, not an error
    cat "$dir/$SA_FILE"
}

sa_put() {
    local acct="$1" dir tmp
    dir=$(resolve_dir "$acct") || exit 1
    tmp=$(mktemp) || die "mktemp failed"
    trap "rm -f '$tmp'" EXIT

    cat > "$tmp" || die "failed to read preferences from stdin"

    # spamd setuids to the account before reading this, so the account must own
    # it. 0600/0700 matches what spamd's own -c would create.
    install -d -m 0700 -o "$acct" -g "$acct" "$dir" || die "failed to create $dir"
    install -m 0600 -o "$acct" -g "$acct" "$tmp" "$dir/$SA_FILE" \
        || die "failed to install $dir/$SA_FILE"
    echo "ok"
}

sa_delete() {
    local dir; dir=$(resolve_dir "$1") || exit 1
    rm -f "$dir/$SA_FILE"
    echo "ok"
}

# Every account's prefs in ONE pass, so the overview page costs a single sudo
# call instead of one per account.
#
# Output is LENGTH-PREFIXED, not delimited by a marker line, because a prefs
# file may contain any line at all, comments included:
#     <account> <byte count>\n<file bytes>
sa_get_all() {
    local acct home f sz
    while IFS=: read -r acct _pass _uid _gid _gecos home _rest; do
        case "$acct" in ''|'#'*) continue ;; esac
        valid_account "$acct" || continue
        case "$home" in "$HOME_ROOT"/*) : ;; *) continue ;; esac
        f="$home/$SA_DIR/$SA_FILE"
        [ -f "$f" ] || continue
        sz=$(stat -c %s "$f" 2>/dev/null) || continue
        printf '%s %s\n' "$acct" "$sz"
        cat "$f"
    done < <(getent passwd)
}

# ── dispatch ─────────────────────────────────────────────────────────────────

verb="${1:-}"; shift || true
case "$verb" in
    get)     [ $# -eq 1 ] || die "usage: get <account>";    sa_get "$1" ;;
    put)     [ $# -eq 1 ] || die "usage: put <account>";    sa_put "$1" ;;
    delete)  [ $# -eq 1 ] || die "usage: delete <account>"; sa_delete "$1" ;;
    get-all) [ $# -eq 0 ] || die "usage: get-all";          sa_get_all ;;
    *) die "unknown verb: ${verb:-<none>}" ;;
esac
