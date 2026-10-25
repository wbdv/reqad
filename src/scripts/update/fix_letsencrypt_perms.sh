#!/bin/bash
# Let's Encrypt file permissions.
#
# Certificates (cert, chain, fullchain) readable by everyone; private keys
# only by root and the exim group (exim and dovecot serve them for mail
# TLS); the ACME account key and certbot's old key copies (accounts/, keys/)
# by root only. The certificate folders stay passable (0755) so exim can
# reach the files it is allowed to read.
#
# Older releases ran `chmod -R a+rx /etc/letsencrypt/` after every renewal,
# which left every private key readable by all local users. This undoes that,
# and runs again from the certbot post hook after each renewal.
#
# Usage: fix_letsencrypt_perms.sh [--check]   (--check: only report, exit 1 if anything is open)

LE=/etc/letsencrypt
[ -d "$LE" ] || exit 0
[ "$(id -u)" = 0 ] || { echo "run as root" >&2; exit 2; }

if [ "${1:-}" = --check ]; then
	open=$(find "$LE/archive" "$LE/keys" "$LE/accounts" -type f \( -name 'privkey*.pem' -o -path "$LE/keys/*" -o -name 'private_key.json' \) -perm /o=r 2>/dev/null | wc -l)
	echo "private keys readable by all: $open"
	[ "$open" = 0 ]
	exit
fi

KEYGROUP=root
getent group exim >/dev/null 2>&1 && KEYGROUP=exim

chmod 0755 "$LE" 2>/dev/null
for d in "$LE/live" "$LE/archive" "$LE"/live/*/ "$LE"/archive/*/; do
	[ -d "$d" ] && chmod 0755 "$d"
done
if [ -d "$LE/archive" ]; then
	find "$LE/archive" -type f \( -name 'cert*.pem' -o -name 'chain*.pem' -o -name 'fullchain*.pem' \) -exec chmod 0644 {} +
	find "$LE/archive" -type f -name 'privkey*.pem' -exec chown "root:$KEYGROUP" {} + -exec chmod 0640 {} +
fi
# the ACME account key and certbot's copies of old keys: root only
for d in "$LE/accounts" "$LE/keys"; do
	[ -d "$d" ] || continue
	chown -R root:root "$d"
	find "$d" -type d -exec chmod 0700 {} +
	find "$d" -type f -exec chmod 0600 {} +
done
# plain settings files are not programs
for f in "$LE/cli.ini" "$LE/options-ssl-nginx.conf" "$LE/ssl-dhparams.pem" "$LE"/.updated-*; do
	[ -f "$f" ] && chmod 0644 "$f"
done
[ -d "$LE/renewal" ] && find "$LE/renewal" -type f -name '*.conf' -exec chmod 0644 {} +
exit 0
