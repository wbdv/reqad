#!/bin/bash
# Reqad email filters — the single privileged entry point for Sieve scripts.
#
# Called from PHP as `sudo -n /usr/local/reqad/scripts/email-filters/ef-helper.sh <verb> ...`
# with every argument escapeshellarg'd. The reqad user has NOPASSWD:ALL, so
# argument validation HERE is the only real boundary — no caller-supplied path
# ever reaches the filesystem unvalidated, and directories are fixed constants.
#
# Script source is read from STDIN, never from an argument: it is multi-line and
# arbitrary, so passing it as an argv element invites quoting bugs.
#
# Verbs
#   system-get    global | domain <domain> | autoresponder <email>
#   system-put    global | domain <domain> | autoresponder <email>   < source
#   system-delete global | domain <domain> | autoresponder <email>
#   user-list     <email>
#   user-folders  <email>
#   user-get      <email>
#   user-get-all
#   user-put      <email>                       < source
#   user-delete   <email>
#
# Exit 0 on success. On failure, exit non-zero with the reason on stderr; for
# system-put a compile error leaves the PREVIOUS script and .svbin untouched.

set -u
export LC_ALL=C

SIEVE_DIR="/var/lib/reqad/sieve"
DOMAIN_DIR="$SIEVE_DIR/domains"
AR_DIR="$SIEVE_DIR/autoresponders"
GLOBAL_BASE="$SIEVE_DIR/reqad-global"
SCRIPT_NAME="reqad"          # the ManageSieve script name Reqad and Roundcube share
OWNER="dovecot:dovecot"
USERS_FILE="/etc/dovecot/users"
DOVECOT_CONF="/etc/dovecot/dovecot.conf"

# Compile with the server's own config, so a script is validated against exactly
# the extension set the runtime will run it with. Without -c, sievec knows
# nothing of sieve_plugins/sieve_implicit_extensions and rejects a "pipe" action
# with "unknown Sieve capability 'vnd.dovecot.pipe'" even though delivery would
# have accepted it. Falls back to a bare sievec where there is no dovecot.conf.
sievec_cmd() {
    if [ -r "$DOVECOT_CONF" ]; then
        sievec -c "$DOVECOT_CONF" "$@"
    else
        sievec "$@"
    fi
}

die() { echo "$*" >&2; exit 1; }

# ── validation ───────────────────────────────────────────────────────────────

valid_domain() {
    case "$1" in
        *[!a-z0-9.-]* | .* | *.. | -* | */* ) return 1 ;;
    esac
    [ ${#1} -le 253 ] || return 1
    printf '%s' "$1" | grep -qE '^[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$'
}

valid_email() {
    case "$1" in *[!a-z0-9._+@-]* | */* ) return 1 ;; esac
    [ ${#1} -le 320 ] || return 1
    printf '%s' "$1" | grep -qE '^[a-z0-9][a-z0-9._+-]*@[a-z0-9][a-z0-9.-]*\.[a-z]{2,}$'
}

# Resolve "global" / "domain <d>" / "autoresponder <email>" to a base path with
# no extension. Echoes the base path; dies on anything unrecognised.
resolve_base() {
    case "${1:-}" in
        global)
            [ $# -eq 1 ] || die "system scope 'global' takes no further argument"
            printf '%s' "$GLOBAL_BASE"
            ;;
        domain)
            [ $# -eq 2 ] || die "system scope 'domain' needs exactly one domain"
            valid_domain "$2" || die "invalid domain: $2"
            printf '%s' "$DOMAIN_DIR/$2"
            ;;
        autoresponder)
            # One vacation script per mailbox. The filename is the full login
            # name because that is what dovecot expands %{user} to.
            [ $# -eq 2 ] || die "system scope 'autoresponder' needs exactly one email address"
            valid_email "$2" || die "invalid address: $2"
            printf '%s' "$AR_DIR/$2"
            ;;
        *)  die "unknown scope: ${1:-<none>} (expected 'global', 'domain' or 'autoresponder')" ;;
    esac
}

# ── system tier (global / per-domain), file-backed, compiled with sievec ─────

system_get() {
    local base; base=$(resolve_base "$@") || exit 1
    [ -f "$base.sieve" ] || exit 0        # missing script is a no-op, not an error
    cat "$base.sieve"
}

system_put() {
    local base; base=$(resolve_base "$@") || exit 1
    local dir; dir=$(dirname "$base")
    local tmp; tmp=$(mktemp -d) || die "mktemp failed"
    trap "rm -rf '$tmp'" EXIT

    cat > "$tmp/s.sieve" || die "failed to read script from stdin"
    [ -s "$tmp/s.sieve" ] || die "refusing to write an empty script (use system-delete)"

    # Stage 1 — validate a throwaway copy, so a syntax error never disturbs what
    # is currently live.
    if ! sievec_cmd "$tmp/s.sieve" "$tmp/s.svbin" 2>"$tmp/err"; then
        sed "s#$tmp/s\.sieve#script#g" "$tmp/err" >&2
        die "sieve compile failed — previous script left in place"
    fi
    # sievec warns on stderr while still succeeding; surface those but continue.
    [ -s "$tmp/err" ] && sed "s#$tmp/s\.sieve#script#g" "$tmp/err" >&2

    mkdir -p "$dir"
    install -m 0644 -o dovecot -g dovecot "$tmp/s.sieve" "$base.sieve" \
        || die "failed to install $base.sieve"

    # Stage 2 — recompile AT THE FINAL PATH. The .svbin records the source path it
    # was built from; a binary compiled from the temp copy is treated as stale, and
    # every delivery then tries to recompile it in place. That fails, because LMTP
    # runs as the mailbox owner and this directory is dovecot-owned:
    #   sieve: binary ...svbin: save: failed to create temporary file:
    #     open(...) failed: Permission denied (missing +w perm: /var/lib/reqad/sieve)
    #   sieve: The LDA Sieve plugin does not have permission to save global Sieve
    #     script binaries; ... need to be pre-compiled using the sievec tool
    # Mail still delivers, but it is recompiled from source every time and logs two
    # errors per message. Compile to a temp name in the SAME directory and rename,
    # so readers never observe a half-written binary.
    local out="$dir/.ef-$$.svbin"
    if ! sievec_cmd "$base.sieve" "$out" 2>"$tmp/err2"; then
        cat "$tmp/err2" >&2
        rm -f "$out"
        die "sieve compile failed at final path"
    fi
    chown dovecot:dovecot "$out" 2>/dev/null
    chmod 0644 "$out"
    mv -f "$out" "$base.svbin" || die "failed to install $base.svbin"

    chown "$OWNER" "$dir" 2>/dev/null
    echo "ok"
}

system_delete() {
    local base; base=$(resolve_base "$@") || exit 1
    rm -f "$base.sieve" "$base.svbin"
    echo "ok"
}

# ── account tier, owned by the mailbox and shared with Roundcube/IMAP ────────

user_list()   { valid_email "$1" || die "invalid address: $1"; doveadm sieve list -u "$1"; }
# The mailbox's IMAP folders, one per line, for the "File into folder" picker.
# Names come out with the namespace separator already applied ("Parent.Child"),
# which is exactly the form `fileinto` expects. No script or state is touched,
# so a mailbox that has never been delivered to simply lists nothing.
user_folders() { valid_email "$1" || die "invalid address: $1"
                doveadm mailbox list -u "$1" 2>/dev/null || exit 0; }
user_get()    { valid_email "$1" || die "invalid address: $1"
                # `doveadm sieve list` prints "<name>" or "<name> ACTIVE" — compare
                # the first field only. No script yet is empty output, not an error.
                doveadm sieve list -u "$1" 2>/dev/null \
                    | awk -v n="$SCRIPT_NAME" '$1 == n { found = 1 } END { exit !found }' \
                    || exit 0
                # `-f tab` emits exactly one header line ("sieve script") and then the
                # script verbatim; the default formatter uses a "sieve script:" prefix
                # that is harder to strip safely. There is no raw formatter.
                # The stored script is byte-faithful (verified); `-f tab` just adds a
                # trailing newline of its own. Strip trailing blank lines and emit
                # exactly one, so Phase 2 can compare get-before-write without a
                # spurious conflict on every save.
                doveadm -f tab sieve get -u "$1" "$SCRIPT_NAME" | tail -n +2 \
                    | awk '{ a[NR] = $0 }
                           END { n = NR; while (n > 0 && a[n] == "") n--;
                                 for (i = 1; i <= n; i++) print a[i] }'; }
# Every mailbox's personal script in ONE pass.
#
# Fetching them one at a time costs two doveadm invocations per mailbox (~60ms
# each), which was almost all of the "all mailboxes" page load. The personal
# script is a plain file inside the mailbox home (90-sieve.conf: sieve_script
# personal { path = ~/sieve }), so the whole inventory is a single walk of the
# userdb — no doveadm at all. Mailboxes with no script are simply not emitted.
#
# Output is LENGTH-PREFIXED, not delimited by a marker line, because a Sieve
# script may contain any line at all, comments included:
#     <email> <byte count>\n<script bytes>
user_get_all() {
    [ -r "$USERS_FILE" ] || exit 0
    local user home f sz
    while IFS=: read -r user _pass _uid _gid _gecos home _rest; do
        case "$user" in ''|'#'*) continue ;; esac
        user=$(printf '%s' "$user" | tr 'A-Z' 'a-z')
        valid_email "$user" || continue
        [ -n "$home" ] || continue
        f="$home/sieve/$SCRIPT_NAME.sieve"
        [ -f "$f" ] || continue
        sz=$(stat -c %s "$f" 2>/dev/null) || continue
        printf '%s %s\n' "$user" "$sz"
        cat "$f"
    done < "$USERS_FILE"
}

user_put()    { valid_email "$1" || die "invalid address: $1"
                doveadm sieve put -a -u "$1" "$SCRIPT_NAME" && echo "ok"; }
user_delete() { valid_email "$1" || die "invalid address: $1"
                doveadm sieve delete -a -u "$1" "$SCRIPT_NAME" && echo "ok"; }

# ── dispatch ─────────────────────────────────────────────────────────────────

verb="${1:-}"; shift || true
case "$verb" in
    system-get)    [ $# -ge 1 ] || die "usage: system-get global|domain <domain>|autoresponder <email>";    system_get "$@" ;;
    system-put)    [ $# -ge 1 ] || die "usage: system-put global|domain <domain>|autoresponder <email>";    system_put "$@" ;;
    system-delete) [ $# -ge 1 ] || die "usage: system-delete global|domain <domain>|autoresponder <email>"; system_delete "$@" ;;
    user-list)     [ $# -eq 1 ] || die "usage: user-list <email>";   user_list "$1" ;;
    user-folders)  [ $# -eq 1 ] || die "usage: user-folders <email>"; user_folders "$1" ;;
    user-get)      [ $# -eq 1 ] || die "usage: user-get <email>";    user_get "$1" ;;
    user-get-all)  [ $# -eq 0 ] || die "usage: user-get-all";        user_get_all ;;
    user-put)      [ $# -eq 1 ] || die "usage: user-put <email>";    user_put "$1" ;;
    user-delete)   [ $# -eq 1 ] || die "usage: user-delete <email>"; user_delete "$1" ;;
    *) die "unknown verb: ${verb:-<none>}" ;;
esac
