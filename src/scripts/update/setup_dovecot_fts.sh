#!/bin/bash
# Enable full-text search in Dovecot 2.4 using the flatcurve (Xapian) backend.
#
# Dovecot 2.3 boxes commonly used fts-solr, which needs a separate Solr/JVM
# service — far too heavy for a single VPS. Dovecot 2.4 ships flatcurve as an
# official sub-package (dovecot-flatcurve), backed by Xapian: no daemon, no
# network service, indexes stored per-mailbox inside the user's mail dir (so
# they are covered by the normal account backup and removed with the account).
#
# Do NOT use EPEL's dovecot-fts-xapian — that is a different, third-party
# project, built against the distro's Dovecot 2.3 with an unversioned
# 'Requires: dovecot'; installing it drags in the 2.3 stack and its config
# syntax is the old plugin{} style.
#
# Config placement: the FTS settings go in their OWN file, /etc/dovecot/fts.conf,
# pulled in by an !include_try line in local.conf. This matters because
# migrate_dovecot_2.4.sh authoritatively wipes and rewrites conf.d/ — anything
# dropped there would be deleted (and flagged as a stray 2.3 leftover) on its
# next self-heal run. fts.conf lives outside conf.d and survives; the include
# line is part of the local.conf that migrate_dovecot_2.4.sh writes.
#
# OPT-IN: this is NOT called from post_reqad_install.sh, and it cannot be. It
# needs to install a package, and an RPM %post scriptlet already holds the rpmdb
# lock — dnf from inside dnf either fails on the lock or blocks waiting for a
# transaction that is waiting for it. (Independently of that: enabling FTS
# restarts Dovecot and eventually indexes every mailbox on the server, which is
# the admin's call to make, not an update's.) Run it by hand:
#
#     bash /usr/local/reqad/scripts/update/setup_dovecot_fts.sh
#
# It is idempotent and self-validating (rolls back if doveconf or Dovecot
# rejects the result), so it is safe to re-run — worth doing after a Dovecot
# package migration, which rewrites /etc/dovecot.
#
# It is also a no-op when email is disabled in server-software.ini, when Dovecot
# is absent, or when Dovecot is not 2.4.

set -uo pipefail

INI="/usr/local/reqad/etc/server-software.ini"
FTS_CONF="/etc/dovecot/fts.conf"
LOCAL_CONF="/etc/dovecot/local.conf"
INCLUDE_LINE="!include_try /etc/dovecot/fts.conf"

if [ "$(id -u)" -ne 0 ]; then
    echo "  This script must be run as root"
    exit 1
fi

# --- Refuse to run inside an RPM/dnf transaction -----------------------------
# We install a package below. If we are being run from an RPM scriptlet (%post),
# the rpmdb lock is already held by the transaction that invoked us, so dnf will
# either fail outright or deadlock waiting for a lock that can only be released
# once we return. Walk up the process tree and bail out clearly if so.
in_package_transaction() {
    local pid=$PPID comm depth=0
    while [ "$pid" -gt 1 ] && [ "$depth" -lt 20 ]; do
        [ -r "/proc/$pid/comm" ] || return 1
        comm=$(<"/proc/$pid/comm")
        case "$comm" in
            rpm|dnf|dnf-3|dnf5|microdnf|yum|packagekitd) return 0 ;;
        esac
        pid=$(awk '{print $4}' "/proc/$pid/stat" 2>/dev/null) || return 1
        [ -n "$pid" ] || return 1
        depth=$((depth + 1))
    done
    return 1
}

if in_package_transaction; then
    echo "  Refusing to run inside an rpm/dnf transaction — the rpmdb is locked"
    echo "  and installing dovecot-flatcurve here would fail or deadlock."
    echo "  Run this script by hand once the update has finished."
    exit 0
fi

# --- Skip when the email stack is disabled for this install ------------------
if [ -f "$INI" ]; then
    EMAIL=$(grep -oP '^email=\K.*' "$INI" | tr -d '[:space:]')
    if [ "${EMAIL:-1}" = "0" ]; then
        echo "  Email disabled in server-software.ini, skipping Dovecot FTS"
        exit 0
    fi
fi

# --- Require Dovecot 2.4 -----------------------------------------------------
DOVECOT_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null)
if [ -z "$DOVECOT_VER" ]; then
    echo "  Dovecot not installed, skipping FTS setup"
    exit 0
fi
if [[ "$DOVECOT_VER" != 2.4* ]]; then
    echo "  Dovecot $DOVECOT_VER is not 2.4 (flatcurve needs 2.4), skipping FTS setup"
    exit 0
fi

# --- Install dovecot-flatcurve ----------------------------------------------
# The package Requires an exact 'dovecot = <epoch>:<ver>-<rel>', so ask for the
# build matching the installed dovecot rather than whatever is newest in the
# repo (which may be ahead of the pinned 2.4.1 repo on EL8).
if ! rpm -q dovecot-flatcurve >/dev/null 2>&1; then
    DOVECOT_EVR=$(rpm -q --qf '%{VERSION}-%{RELEASE}' dovecot 2>/dev/null)
    echo "  Installing dovecot-flatcurve-${DOVECOT_EVR} ..."
    DNF_OUT=$(dnf install -y "dovecot-flatcurve-${DOVECOT_EVR}" 2>&1)
    if [ $? -ne 0 ]; then
        # Fall back to an unversioned request in case release strings differ.
        DNF_OUT=$(dnf install -y dovecot-flatcurve 2>&1)
        if [ $? -ne 0 ]; then
            echo "  WARNING: could not install dovecot-flatcurve — FTS not enabled"
            echo "$DNF_OUT" | tail -10 | sed 's/^/    /'
            exit 0
        fi
    fi
    echo "  dovecot-flatcurve installed."
else
    echo "  dovecot-flatcurve already installed."
fi

# The plugin .so must actually be there before we reference it in the config,
# otherwise Dovecot refuses to start.
if ! ls /usr/lib64/dovecot/lib*_fts_flatcurve_plugin.so >/dev/null 2>&1; then
    echo "  WARNING: fts_flatcurve plugin not found in /usr/lib64/dovecot — FTS not enabled"
    exit 0
fi

# --- Write /etc/dovecot/fts.conf --------------------------------------------
# Keep a copy of the previous config so a failed doveconf check can roll back.
BACKUP_CONF=""
if [ -f "$FTS_CONF" ]; then
    BACKUP_CONF="${FTS_CONF}.prev"
    cp -a "$FTS_CONF" "$BACKUP_CONF"
fi

echo "  Writing $FTS_CONF ..."
cat > "$FTS_CONF" <<'EOF'
# Full-text search — managed by Reqad (scripts/update/setup_dovecot_fts.sh).
# Edits here are overwritten on package update; use a separate include instead.

mail_plugins {
  fts = yes
  fts_flatcurve = yes
}

# FTS is the first feature that makes Dovecot CREATE directories inside an
# existing maildir (the per-mailbox fts-flatcurve/ index dir). Dovecot gives a
# new dir the group of its parent, and Reqad's maildirs are <user>:mail — so
# without membership of 'mail' the fchown fails with EPERM ("Operation not
# permitted ... group based on ..."), Dovecot unwinds the half-created dir, and
# the follow-up mkdir reports a misleading EACCES even though the UNIX perms on
# the parent are fine. Granting the supplementary group is the documented fix.
mail_access_groups = mail

# Tokenisers. These default to EMPTY in 2.4, and an empty list makes flatcurve
# fail at backend init with "Empty language_tokenizers { .. } list" — note that
# doveconf still accepts the file, so this cannot be caught by a config check,
# and IMAP SEARCH silently falls back to a slow brute-force scan (searches keep
# "working", just without any index). Must be set explicitly.
#   generic       — normal word splitting
#   email-address — keeps addresses searchable as whole tokens
language_tokenizers = generic email-address

# Filters applied to each token. Also empty by default.
#   normalizer-icu — lowercase + strip accents (ICU); makes search
#                    case- and diacritic-insensitive, which matters for Romanian
#   snowball       — stemming, so "running" matches "run" (libstemmer)
#   stopwords      — drop "the", "and", … using /usr/share/dovecot/stopwords
language_filters = normalizer-icu snowball stopwords

# Tokenisation language. At least one language with default=yes is required
# whenever FTS is enabled, or Dovecot fails to start.
language en {
  default = yes
  # english-possessive strips trailing "'s" — English-specific, so it goes here
  # rather than in the global language_filters list above.
  filters = normalizer-icu snowball english-possessive stopwords
}

# Index new mail as it is delivered/appended.
fts_autoindex = yes

# Index anything not yet indexed at search time, so searches over pre-existing
# mail work without a manual full reindex.
fts_search_add_missing = yes

# Don't try to index enormous messages — one 200MB attachment would otherwise
# stall the indexer worker.
fts_message_max_size = 50M

fts flatcurve {
  # Upstream defaults are appropriate for almost every deployment; listed here
  # so they are visible and easy to tune.
  commit_limit   = 500
  min_term_size  = 2
  optimize_limit = 10
  rotate_count   = 5000
  # rotate_time is a time interval: 2.4 rejects a bare number ("missing units").
  # Upstream documents the default as 5000, meaning milliseconds.
  rotate_time    = 5000ms

  # RFC 3501 substring matching. Costs a great deal of index size and indexing
  # time; prefix/whole-word matching is what mail clients actually use.
  substring_search = no
}
EOF
chown root:root "$FTS_CONF"
chmod 0644 "$FTS_CONF"

# --- Make sure local.conf pulls it in ----------------------------------------
if [ -f "$LOCAL_CONF" ]; then
    if ! grep -qF "$INCLUDE_LINE" "$LOCAL_CONF"; then
        echo "  Adding FTS include to $LOCAL_CONF ..."
        printf '\n# Full-text search (flatcurve)\n%s\n' "$INCLUDE_LINE" >> "$LOCAL_CONF"
    fi
else
    echo "  WARNING: $LOCAL_CONF missing — creating it with the FTS include"
    printf '# Full-text search (flatcurve)\n%s\n' "$INCLUDE_LINE" > "$LOCAL_CONF"
    chmod 0644 "$LOCAL_CONF"
fi

# --- Validate, then restart --------------------------------------------------
if ! doveconf -n >/dev/null 2>&1; then
    echo "  ERROR: doveconf rejected the new configuration — rolling back FTS"
    doveconf -n 2>&1 | tail -5 | sed 's/^/    /'
    sed -i "\|^${INCLUDE_LINE}\$|d; \|^# Full-text search (flatcurve)\$|d" "$LOCAL_CONF"
    if [ -n "$BACKUP_CONF" ]; then
        mv -f "$BACKUP_CONF" "$FTS_CONF"
    else
        rm -f "$FTS_CONF"
    fi
    exit 0
fi
rm -f "${FTS_CONF}.prev"

if systemctl is-active --quiet dovecot; then
    echo "  Restarting Dovecot ..."
    if ! systemctl restart dovecot; then
        echo "  ERROR: Dovecot failed to restart — disabling FTS again"
        sed -i "\|^${INCLUDE_LINE}\$|d; \|^# Full-text search (flatcurve)\$|d" "$LOCAL_CONF"
        rm -f "$FTS_CONF"
        systemctl restart dovecot || true
        exit 0
    fi
fi

# --- Verify the backend actually initialises ---------------------------------
# doveconf accepting the config proves nothing here: a missing tokeniser/filter
# list parses fine and only fails when the backend is instantiated, after which
# IMAP SEARCH quietly degrades to a brute-force scan. So poke a real user.
# Best-effort — skipped when no mail users exist yet.
FIRST_USER=$(grep -v '^[#:]' /etc/dovecot/users 2>/dev/null | head -1 | cut -d: -f1)
if [ -n "$FIRST_USER" ]; then
    echo "  Verifying FTS backend with user $FIRST_USER ..."
    FTS_OUT=$(doveadm fts optimize -u "$FIRST_USER" 2>&1)
    if echo "$FTS_OUT" | grep -q "Failed to initialize backend\|fts not enabled"; then
        echo "  WARNING: FTS is configured but the backend did not initialise:"
        echo "$FTS_OUT" | grep -E "Error|Failed" | head -5 | sed 's/^/    /'
        echo "  Mail delivery and IMAP are unaffected, but searches will fall back"
        echo "  to a slow full scan. Fix $FTS_CONF, then re-run this script."
        exit 1
    fi
    echo "  Backend OK."
fi

echo "  Dovecot full-text search enabled (flatcurve/Xapian)."
echo "  Existing mail is indexed lazily on first search. To build all indexes up"
echo "  front:"
echo "      doveadm index -A '*'          # indexes now, blocks until done"
echo "      doveadm index -A -q '*'       # only QUEUES it for the indexer process"
echo "  Note -q returns immediately and indexes nothing itself — it is the safer"
echo "  option on a busy server, but if the indexer is not draining the queue you"
echo "  get silence and empty indexes. Verify with:"
echo "      doveadm fts flatcurve stats -u <user> '*'"
exit 0
