#!/bin/bash
# Migrate Dovecot from 2.3 to 2.4.
#
# Strategy (do NOT try to auto-migrate the 2.3 config — it produces a broken
# 2.4 config and leaves .rpmnew files behind):
#   1. Preserve only what is host-specific: sni.conf, users (email accounts),
#      and dh.pem. Everything else is replaced with known-good 2.4 config.
#   2. Move the whole 2.3 /etc/dovecot aside BEFORE the package upgrade so the
#      RPM lays down clean 2.4 defaults with no .rpmnew/.rpmsave noise.
#   3. Install/upgrade to 2.4, then write our own 2.4 config files over the
#      defaults and restore sni.conf + users + dh.pem.
#
# IMPORTANT: conf.d is wiped and rebuilt from scratch below. A 2.3 install ships
# ~24 conf.d files (auth-*.conf.ext, 15-mailboxes.conf, 90-*.conf, …); the 2.4
# RPM owns only 8. If any 2.3 leftover survives in conf.d (RPM restoring a
# %config file, a dnf module quirk, or a re-run after a partial migration) the
# '!include_try conf.d/*.conf' below drags it back in and the 2.4 parse fails.
# So we never trust the dir to be clean — we remove it and recreate it.
#
# Idempotent & self-healing: if already on 2.4 with clean config it exits 0; if
# already on 2.4 but old 2.3 config is still present, it re-runs the config
# rewrite to repair it (without touching the package or host data).

set -euo pipefail

BACKUP="/etc/dovecot.backup-2.3"
REPO="/etc/yum.repos.d/dovecot.repo"
DOVECOT_VER="2.4.1"

# The 2.4 config we lay down: exactly these files belong in conf.d. Anything
# else is a 2.3 leftover that would be included by '!include_try conf.d/*.conf'.
EXPECTED_CONFD="10-auth.conf 10-mail.conf 10-master.conf 10-ssl.conf 20-imap.conf 20-lmtp.conf 20-pop3.conf 20-submission.conf"

# Echo the basename of any conf.d file that is NOT in our known-good 2.4 set.
stray_confd() {
    [ -d /etc/dovecot/conf.d ] || return 0
    local f base
    for f in /etc/dovecot/conf.d/*; do
        [ -e "$f" ] || continue
        base=$(basename "$f")
        case " $EXPECTED_CONFD " in
            *" $base "*) ;;        # expected — ok
            *) echo "$base" ;;     # stray/old 2.3 file
        esac
    done
}

# --- Root check ---
[ "$(id -u)" -eq 0 ] || { echo "ERROR: Must run as root."; exit 1; }

CURRENT_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || echo "")

# --- Decide: full package migration, config-only repair, or nothing ---------
UPGRADE_PKG=1
if [[ "$CURRENT_VER" == 2.4* ]]; then
    UPGRADE_PKG=0
    if [ -z "$(stray_confd)" ]; then
        echo "Dovecot is already on 2.4 ($CURRENT_VER) and config is clean. Nothing to do."
        exit 0
    fi
    echo "Dovecot is already on 2.4 ($CURRENT_VER) but old 2.3 config remains:"
    stray_confd | sed 's/^/    conf.d\//'
    echo "Repairing config (package left as-is)..."
elif [[ "$CURRENT_VER" != 2.3* ]]; then
    echo "ERROR: Expected Dovecot 2.3.x or 2.4.x, found '${CURRENT_VER:-not installed}'."
    exit 1
else
    echo "Dovecot $CURRENT_VER → 2.4 migration"
fi

# --- Detect OS major version ---
OS_VER=$(grep -oP '(?<=^VERSION_ID=")\d+' /etc/os-release 2>/dev/null \
    || grep -oP '\b[89]\b' /etc/redhat-release | head -1)
echo "OS major version: $OS_VER"

# --- Stop the running daemon before touching its config ---
systemctl stop dovecot 2>/dev/null || true

if [[ "$UPGRADE_PKG" -eq 1 ]]; then
    # --- Move the entire 2.3 config aside -----------------------------------
    # Removing the live config dir before the upgrade means the RPM reinstalls
    # its %config files cleanly (no .rpmnew, since the on-disk files are absent).
    # We keep the dir as a backup and restore sni.conf / users / dh.pem from it.
    echo "Backing up /etc/dovecot/ → $BACKUP ..."
    rm -rf "$BACKUP"
    mv /etc/dovecot "$BACKUP"
    echo "Backup done."

    # --- Update repo --------------------------------------------------------
    echo "Updating $REPO ..."
    if [ "${OS_VER}" -ge 9 ]; then
        cat > "$REPO" <<'EOF'
[dovecot-2.4-latest]
name=Dovecot 2.4 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4-latest/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.4
gpgcheck=1
enabled=1
EOF
    else
        cat > "$REPO" <<'EOF'
[dovecot-2.4.1]
name=Dovecot 2.4.1 RHEL $releasever - $basearch
baseurl=http://repo.dovecot.org/ce-2.4.1/rhel/$releasever/RPMS/$basearch
gpgkey=https://repo.dovecot.org/DOVECOT-REPO-GPG-2.3
gpgcheck=1
enabled=1
EOF
    fi
    echo "Repo updated."

    # --- Upgrade Dovecot and install 2.4 sub-packages -----------------------
    # In 2.4, IMAP/POP3/LMTP/submission are separate packages.
    # dovecot-submissiond is intentionally NOT installed — exim is the MSA on
    # ports 587/465 (a disabling 20-submission.conf is written below regardless).
    echo "Upgrading Dovecot and installing 2.4 sub-packages..."
    DOVECOT_PKGS=$(rpm -qa 'dovecot-*' --qf '%{NAME} ' 2>/dev/null || true)
    dnf install -y dovecot $DOVECOT_PKGS \
        dovecot-imapd dovecot-pop3d dovecot-lmtpd 2>&1

    NEW_VER=$(rpm -q --qf '%{VERSION}' dovecot 2>/dev/null || echo "unknown")
    echo "Upgraded to: $NEW_VER"
else
    NEW_VER="$CURRENT_VER"
fi

# --- Clean any stray .rpmnew/.rpmsave (belt-and-suspenders) ------------------
find /etc/dovecot \( -name '*.rpmnew' -o -name '*.rpmsave' \) -delete 2>/dev/null || true

# --- Write known-good 2.4 config --------------------------------------------
# Authoritatively reset conf.d. The pre-upgrade backup already holds the 2.3
# originals; wiping guarantees the ONLY files '!include_try conf.d/*.conf' can
# pull in are the eight we write below — no 2.3 leftovers, whatever their origin.
echo "Writing 2.4 configuration files..."
rm -rf /etc/dovecot/conf.d
mkdir -p /etc/dovecot/conf.d

cat > /etc/dovecot/dovecot.conf <<EOF
## Dovecot configuration shipped with redhat packages

dovecot_config_version = $DOVECOT_VER
dovecot_storage_version = $DOVECOT_VER

protocols =

!include_try conf.d/*.conf
!include_try local.conf
EOF

cat > /etc/dovecot/local.conf <<'EOF'
# SSL
ssl = required
ssl_server_cert_file = /etc/pki/dovecot/certs/dovecot.pem
ssl_server_key_file = /etc/pki/dovecot/private/dovecot.pem
ssl_server_dh_file = /etc/dovecot/dh.pem
ssl_min_protocol = TLSv1.2
ssl_server_prefer_ciphers = client
!include_try /etc/dovecot/sni.conf

# Mail — override conf.d/10-mail.conf defaults
mail_path = ~/
mail_inbox_path = ~/

# Auth
auth_mechanisms = plain login

passdb passwd-file {
  passwd_file_path = /etc/dovecot/users
  default_password_scheme = CRYPT
}
userdb passwd-file {
  passwd_file_path = /etc/dovecot/users
}
EOF

cat > /etc/dovecot/conf.d/10-auth.conf <<'EOF'
# Auth handled by local.conf
EOF

# Submission conflicts with exim on ports 587/465. Overwrite (don't rm) so that
# if dovecot-submissiond is ever installed, RPM keeps our file and drops its
# default as 20-submission.conf.rpmnew instead of enabling the protocol.
cat > /etc/dovecot/conf.d/20-submission.conf <<'EOF'
# Submission disabled — exim is the MSA on 587/465 (no protocols block = off)
EOF

# auth-client socket must be readable by exim (runs as exim, in the mail group)
# for SMTP AUTH. The 2.4 default leaves it 0600 dovecot:root, which breaks exim
# auth — restore the 2.3 behaviour: owned by mail, mode 0660.
cat > /etc/dovecot/conf.d/10-master.conf <<'EOF'
service auth {
  unix_listener auth-client {
    mode = 0660
    user = mail
    group = mail
  }
}
EOF

cat > /etc/dovecot/conf.d/10-mail.conf <<'EOF'
mail_driver = maildir
mail_home = %h
mail_path = ~/mail
mail_inbox_path = /var/mail/%n
mailbox_list_utf8 = yes

namespace inbox {
   separator = /
   inbox = yes
}
EOF

cat > /etc/dovecot/conf.d/10-ssl.conf <<'EOF'
#ssl_server {
#   cert_file = /etc/pki/tls/certs/ssl-cert-snakeoil.pem
#   key_file = /etc/pki/tls/private/ssl-cert-snakeoil.key
#}
EOF

cat > /etc/dovecot/conf.d/20-imap.conf <<'EOF'
# Enable IMAP protocol
protocols {
  imap = yes
}
EOF

cat > /etc/dovecot/conf.d/20-lmtp.conf <<'EOF'
# Enable LMTP protocol
protocols {
  lmtp = yes
}
EOF

cat > /etc/dovecot/conf.d/20-pop3.conf <<'EOF'
# Enable POP3 protocol
protocols {
  pop3 = yes
}
EOF

# --- Restore host-specific files --------------------------------------------
# Only on a real package migration (when we moved the live /etc/dovecot aside).
# On a config-only repair the live sni.conf/users are the current ones — leave
# them be, don't overwrite with a possibly stale 2.3 backup.
if [[ "$UPGRADE_PKG" -eq 1 ]]; then
    # sni.conf is regenerated by Reqad but restore it so SSL keeps working until
    # the next regeneration; users is the email-accounts password file.
    for f in sni.conf users; do
        if [ -f "$BACKUP/$f" ]; then
            cp -a "$BACKUP/$f" "/etc/dovecot/$f"
            echo "  Restored $f"
        else
            echo "  WARNING: $BACKUP/$f not found — skipping"
        fi
    done
fi

# dh.pem: keep the live one; otherwise restore from backup or generate fresh.
if [ ! -f /etc/dovecot/dh.pem ]; then
    if [ -f "$BACKUP/dh.pem" ]; then
        cp -a "$BACKUP/dh.pem" /etc/dovecot/dh.pem
        echo "  Restored dh.pem"
    else
        echo "  Generating dh.pem (this can take a minute)..."
        openssl dhparam -out /etc/dovecot/dh.pem 2048
    fi
fi

# Self-signed default cert. A 2.3 box already has one, but a box that never had a
# cert (or went straight to 2.4) would be missing it, and local.conf's
# ssl_server_cert_file points here — without it doveconf fails fatally. Reqad
# replaces this with per-domain Let's Encrypt certs via SNI; this is the fallback.
if [ ! -f /etc/pki/dovecot/certs/dovecot.pem ]; then
    echo "  Generating self-signed dovecot cert..."
    mkdir -p /etc/pki/dovecot/certs /etc/pki/dovecot/private
    openssl req -new -x509 -nodes -days 3650 \
        -subj "/CN=$(hostname -f 2>/dev/null || hostname)/O=Reqad" \
        -out /etc/pki/dovecot/certs/dovecot.pem \
        -keyout /etc/pki/dovecot/private/dovecot.pem
    chmod 0600 /etc/pki/dovecot/private/dovecot.pem
fi

chown -R root:root /etc/dovecot

# --- Group membership -------------------------------------------------------
# dovecot must share groups with exim/mail/mysyslog so the auth-client socket
# (mail:mail) and mail spool/log access work. Lost on package upgrade.
usermod -G dovecot,mail,exim,mysyslog dovecot

# --- Restart and verify -----------------------------------------------------
echo "Restarting Dovecot..."
if ! systemctl restart dovecot; then
    echo "ERROR: Dovecot failed to start."
    if [ -d "$BACKUP" ]; then
        echo "Restoring 2.3 backup..."
        rm -rf /etc/dovecot
        cp -a "$BACKUP" /etc/dovecot
        systemctl restart dovecot || true
    fi
    exit 1
fi

if systemctl is-active --quiet dovecot; then
    systemctl enable dovecot 2>/dev/null || true
    echo "Dovecot $NEW_VER is running."
    if [[ "$UPGRADE_PKG" -eq 1 ]]; then
        echo "Migration complete. Old 2.3 config backed up at $BACKUP"
    else
        echo "Config repair complete."
    fi
else
    echo "ERROR: Dovecot not active after restart."
    if [ -d "$BACKUP" ]; then
        echo "Restoring 2.3 backup..."
        rm -rf /etc/dovecot
        cp -a "$BACKUP" /etc/dovecot
        systemctl restart dovecot || true
    fi
    exit 1
fi
