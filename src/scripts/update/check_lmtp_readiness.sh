#!/bin/bash
# Pre-flight gate for switching local delivery from exim appendfile to dovecot LMTP.
#
# Exim's virtual_user router accepts a recipient purely on its presence in
# /etc/exim/domains/<domain>, and virtual_users_trans then creates the maildir on
# the fly. Dovecot LMTP instead rejects any address with no /etc/dovecot/users
# entry, so an address that is genuinely deliverable today can start BOUNCING the
# moment the transport is flipped.
#
# A naive diff of the two files over-reports badly. An address listed in
# /etc/exim/domains but absent from /etc/dovecot/users is only a real problem if
# it is actually deliverable today, which needs three more facts:
#
#   * is its domain owned?     /etc/exim/userdomains — if not, virtual_users_trans
#                              expands to /home//mail/... and delivery is ALREADY
#                              broken. Cleanup, not a regression.
#   * is it a forwarder?       /etc/exim/forwards/<domain> — the `forwards` router
#                              handles it before virtual_user; no mailbox needed.
#   * is it lowercase?         dovecot lowercases usernames before userdb lookup.
#
# Run this before flipping the transport. Non-zero exit means do not flip.
#
# Read-only — inspects files, changes nothing.

export LC_ALL=C

EXIM_DOMAINS="/etc/exim/domains"
EXIM_FORWARDS="/etc/exim/forwards"
EXIM_USERDOMAINS="/etc/exim/userdomains"
DOVECOT_USERS="/etc/dovecot/users"

TMP=$(mktemp -d) || exit 1
trap 'rm -rf "$TMP"' EXIT

fail=0

for f in "$EXIM_DOMAINS" "$EXIM_USERDOMAINS" "$DOVECOT_USERS"; do
    if ! sudo test -e "$f"; then
        echo "ERROR: $f is missing — is the mail stack configured?" >&2
        exit 1
    fi
done

sudo cut -d: -f1 "$DOVECOT_USERS" | grep -v '^[[:space:]]*$' | sort -u > "$TMP/dovecot"

: > "$TMP/blockers"
: > "$TMP/orphans"
: > "$TMP/forwarders"
: > "$TMP/mixedcase"
: > "$TMP/orphan_domains"

n_exim=0
for f in "$EXIM_DOMAINS"/*; do
    sudo test -f "$f" || continue
    domain=$(basename "$f")

    # is the domain owned by a system account?
    owner=$(sudo grep "^${domain}:" "$EXIM_USERDOMAINS" 2>/dev/null | cut -d: -f2)
    if [ -z "$owner" ]; then
        echo "$domain" >> "$TMP/orphan_domains"
    fi

    while read -r lp; do
        [ -n "$lp" ] || continue
        n_exim=$((n_exim + 1))
        addr="${lp}@${domain}"

        # already has a dovecot mailbox → fine
        if grep -qxF "$addr" "$TMP/dovecot"; then
            case "$lp" in *[[:upper:]]*) echo "$addr" >> "$TMP/mixedcase" ;; esac
            continue
        fi

        # no mailbox. Is it deliverable today at all?
        if [ -z "$owner" ]; then
            echo "$addr" >> "$TMP/orphans"
        elif sudo grep -qE "^${lp}[[:space:]:]" "$EXIM_FORWARDS/$domain" 2>/dev/null; then
            echo "$addr" >> "$TMP/forwarders"
        else
            echo "$addr" >> "$TMP/blockers"
        fi
    done < <(sudo grep -vE '^[[:space:]]*(#|$)' "$f" 2>/dev/null)
done

n_dovecot=$(wc -l < "$TMP/dovecot")
echo "exim addresses: $n_exim   dovecot users: $n_dovecot"
echo

# ── real blockers ────────────────────────────────────────────────────────────

if [ -s "$TMP/blockers" ]; then
    echo "FAIL: deliverable today, but no $DOVECOT_USERS entry."
    echo "      These would start bouncing under LMTP:"
    sed 's/^/        /' "$TMP/blockers"
    echo "      Fix: create the mailbox in Reqad, or remove the local part from"
    echo "           $EXIM_DOMAINS/<domain> if it is stale."
    echo
    fail=1
fi

if [ -s "$TMP/mixedcase" ]; then
    echo "FAIL: non-lowercase local parts."
    echo "      Dovecot lowercases usernames before userdb lookup, so these will"
    echo "      not match their $DOVECOT_USERS entry:"
    sed 's/^/        /' "$TMP/mixedcase"
    echo "      Fix: recreate these mailboxes lowercase."
    echo
    fail=1
fi

# ── not blockers, but worth cleaning ─────────────────────────────────────────

if [ -s "$TMP/orphan_domains" ]; then
    echo "WARN: $EXIM_DOMAINS files whose domain has no owner in $EXIM_USERDOMAINS."
    echo "      virtual_users_trans expands to /home//mail/... for these, so mailbox"
    echo "      delivery is ALREADY broken — flipping to LMTP does not regress them,"
    echo "      it just changes the failure into a clean 'user unknown'. Safe to remove:"
    sed 's/^/        /' "$TMP/orphan_domains"
    echo
fi

if [ -s "$TMP/orphans" ]; then
    echo "      ...affected addresses:"
    sed 's/^/        /' "$TMP/orphans"
    echo
fi

if [ -s "$TMP/forwarders" ]; then
    echo "INFO: no mailbox, but handled by $EXIM_FORWARDS/<domain> before"
    echo "      virtual_user is reached. Not a blocker:"
    sed 's/^/        /' "$TMP/forwarders"
    echo
fi

# ── dovecot side ─────────────────────────────────────────────────────────────

comm -13 "$TMP/dovecot" /dev/null >/dev/null 2>&1
for addr in $(cat "$TMP/dovecot"); do
    domain="${addr#*@}"; lp="${addr%@*}"
    if ! sudo test -f "$EXIM_DOMAINS/$domain" || \
       ! sudo grep -qxF "$lp" "$EXIM_DOMAINS/$domain" 2>/dev/null; then
        echo "$addr" >> "$TMP/only_dovecot"
    fi
done
if [ -s "$TMP/only_dovecot" ]; then
    echo "WARN: in $DOVECOT_USERS but not routable via $EXIM_DOMAINS."
    echo "      These already cannot receive mail — stale, not a blocker:"
    sed 's/^/        /' "$TMP/only_dovecot"
    echo
fi

# ── plumbing ─────────────────────────────────────────────────────────────────

if [ ! -S /var/run/dovecot/lmtp ]; then
    echo "INFO: /var/run/dovecot/lmtp does not exist — dovecot has not been"
    echo "      restarted since the 'service lmtp' block was added, or the block"
    echo "      is missing from conf.d/10-master.conf."
    echo
fi

# ── verdict ──────────────────────────────────────────────────────────────────

if [ $fail -ne 0 ]; then
    echo "NOT READY for LMTP delivery — resolve the FAIL items above first."
    exit 1
fi

echo "READY: every address deliverable today has a dovecot mailbox."
exit 0
