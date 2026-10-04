#!/bin/bash
#
# build-skiprbl.sh -- generate /etc/exim/skiprblhosts and the SpamAssassin
#                     rules in /etc/mail/spamassassin/reqad-esp.cf
#
# Walks the SPF records of the well-known ESPs listed in
# etc/skiprbl-providers.ini and writes their sending ranges to a host list
# exim consults before every DNS blocklist lookup, grouped under a comment
# naming the provider each range came from.
#
# The same ranges become one SpamAssassin rule per provider (REQAD_ESP_<KEY>),
# matched against the relay that handed the message to this server, scored
# from the catalogue's `sa_score`. A small offset, not a whitelist.
#
# The old hand-maintained file was a flat list of 121 addresses with no
# record of who any of them belonged to, so nothing in it could ever be
# retired with confidence. Everything here is traceable to a provider or to
# the operator's own additions, which live in the panel (settings table,
# skiprbl-extra) rather than in this file -- the file is disposable output.
#
# Runs standalone on a server without Reqad too: nothing but the catalogue is
# required, provider choices then come from the catalogue's `default`, and
# additional addresses can be given as a file.
#
# Usage:
#   build-skiprbl.sh [options]
#
#   --ini PATH        provider catalogue (default: Reqad's etc/, else the
#                     skiprbl-providers.ini next to this script)
#   --extra FILE      additional addresses, one per line, "# note" allowed
#                     (added to the ones stored in the Reqad panel)
#   --out PATH        exim host list          (default /etc/exim/skiprblhosts)
#   --sa-out PATH     SpamAssassin rules  (default .../reqad-esp.cf)
#   --sa-local-score N  score for the additional addresses (default -0.5)
#   --no-exim         do not write the exim host list
#   --no-sa           do not write the SpamAssassin rules
#   --dry-run         write the exim list to stdout, touch nothing
#   --print-sa        write the SpamAssassin rules to stdout, touch nothing
#   --force           install the result even if it shrank sharply (see below)
#   --quiet
#
# Exit: 0 generated (or no change), 1 error, 2 refused -- result looked wrong
#
# Reqad -- https://www.reqad.com/

set -u

PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export PATH

# sort and comm must agree on ordering. Under a UTF-8 locale sort collates
# "185.189.236.0/22" ignoring the punctuation while comm compares bytes, so
# comm decides its input is unsorted and silently drops most of it -- which
# showed up as providers reporting one address instead of fourteen.
LC_ALL=C
export LC_ALL

REQAD=/usr/local/reqad
INI=''
DB="$REQAD/db/reqad.db"
SQLITE=/usr/bin/sqlite3
OUT=/etc/exim/skiprblhosts
SA_OUT=/etc/mail/spamassassin/reqad-esp.cf
SA_LOCAL_SCORE=-0.5
EXTRA_IN=''
LOCK=/run/reqad-skiprbl.lock

DNS_TIMEOUT=4
DNS_TRIES=2
MAX_DEPTH=8

# A run that loses more than this fraction of the provider addresses it found
# last time is treated as a DNS failure, not as a real change. Without the
# guard a resolver outage would quietly empty the file and every ESP would
# start being judged on blocklist hits again.
SHRINK_FLOOR_PCT=60

DRY=0; PRINT_SA=0; FORCE=0; QUIET=0; DO_EXIM=1; DO_SA=1

while [ $# -gt 0 ]; do
	case "$1" in
		--ini)            INI=${2:-}; shift 2 ;;
		--extra)          EXTRA_IN=${2:-}; shift 2 ;;
		--out)            OUT=${2:-}; shift 2 ;;
		--sa-out)         SA_OUT=${2:-}; shift 2 ;;
		--sa-local-score) SA_LOCAL_SCORE=${2:-}; shift 2 ;;
		--no-exim)        DO_EXIM=0; shift ;;
		--no-sa)          DO_SA=0; shift ;;
		--dry-run)        DRY=1; shift ;;
		--print-sa)       DRY=1; PRINT_SA=1; shift ;;
		--force)          FORCE=1; shift ;;
		--quiet)          QUIET=1; shift ;;
		-h|--help) sed -n '2,45p' "$0"; exit 0 ;;
		*) echo "build-skiprbl.sh: unknown option: $1" >&2; exit 1 ;;
	esac
done

say()  { [ "$QUIET" = 1 ] || echo "$@" >&2; }
warn() { echo "build-skiprbl.sh: $*" >&2; }
die()  { warn "$*"; exit 1; }

if [ -z "$INI" ]; then
	INI="$REQAD/etc/skiprbl-providers.ini"
	[ -r "$INI" ] || INI="$(dirname "$(readlink -f "$0")")/skiprbl-providers.ini"
fi
[ -r "$INI" ] || die "provider catalogue not found: $INI (use --ini)"
[ -z "$EXTRA_IN" ] || [ -r "$EXTRA_IN" ] || die "extra address file not readable: $EXTRA_IN"
command -v dig >/dev/null 2>&1 || die "dig not found (install bind-utils)"
[ "$PRINT_SA" = 1 ] && DO_SA=1

# SpamAssassin is optional: no configuration directory means nothing to feed.
# The rules are generated in perl, which SpamAssassin itself guarantees -- on
# a system without a perl in PATH the bundled one of the hosting platform is
# used instead.
SA_BIN=''; PERL=''
if [ "$DO_SA" = 1 ]; then
	for c in "$(command -v spamassassin 2>/dev/null)" /usr/local/cpanel/3rdparty/bin/spamassassin; do
		[ -n "$c" ] && [ -x "$c" ] && { SA_BIN=$c; break; }
	done
	for c in "$(command -v perl 2>/dev/null)" /usr/local/cpanel/3rdparty/bin/perl; do
		[ -n "$c" ] && [ -x "$c" ] && { PERL=$c; break; }
	done
	if [ ! -d "$(dirname "$SA_OUT")" ] || [ -z "$SA_BIN" ]; then
		[ "$PRINT_SA" = 1 ] && die "SpamAssassin not found"
		say "  (SpamAssassin not found -- no rules written)"
		DO_SA=0
	elif [ -z "$PERL" ]; then
		warn "perl not found -- SpamAssassin rules not written"
		DO_SA=0
	fi
fi
[ "$DO_EXIM" = 1 ] || [ "$DO_SA" = 1 ] || die "nothing to do (--no-exim and no SpamAssassin output)"

TMPDIR_RUN=$(mktemp -d /tmp/skiprbl.XXXXXX) || die "cannot create temp dir"
trap 'rm -rf "$TMPDIR_RUN"' EXIT

# Serialise against the panel's "Rebuild" button and the nightly cron run, so
# two generators can never interleave into the same output file.
if [ "$DRY" = 0 ]; then
	# No `2>/dev/null` on this exec: with no command, the redirect applies to
	# the shell itself and is PERMANENT -- it threw away every warning and
	# refusal message for the rest of the run, so a refused rebuild reached the
	# panel as an empty error.
	exec 9>"$LOCK" || die "cannot open lock file: $LOCK"
	flock -w 60 9  || die "another build is already running (lock: $LOCK)"
fi

# ---------------------------------------------------------------- settings --
# Read as the panel stores them. Names are literals from this script, so
# there is nothing user-supplied in the SQL.
db_setting() {
	[ -r "$DB" ] || return 0
	[ -x "$SQLITE" ] || return 0
	"$SQLITE" -init /dev/null -batch -noheader -list "$DB" \
		"SELECT value FROM settings WHERE name='$1';" 2>/dev/null
}

DISABLED=" $(db_setting skiprbl-disabled | tr ',' ' ') "
ENABLED=" $(db_setting skiprbl-enabled  | tr ',' ' ') "
EXTRA_RAW=$(db_setting skiprbl-extra)
if [ -n "$EXTRA_IN" ]; then
	EXTRA_RAW="$EXTRA_RAW"$'\n'"$(cat "$EXTRA_IN")"
fi

# Three-way, not a boolean. An explicit choice always wins; otherwise the
# catalogue's `default` decides. That is what lets a provider be shipped
# switched off (a known spam source nobody wants exempted) and stay off after
# an update, without a preference saved before it existed turning it on.
provider_is_on() {
	local k=$1 def=$2
	case " $DISABLED " in *" $k "*) return 1 ;; esac
	case " $ENABLED "  in *" $k "*) return 0 ;; esac
	case "$(echo "$def" | tr -d ' \t"'"'"'' | tr 'A-Z' 'a-z')" in
		off|no|0|false|disabled) return 1 ;;
	esac
	return 0
}

# ------------------------------------------------------------- validation --
valid_addr() {
	local a=$1 ip len o
	case "$a" in
		''|*[!0-9a-fA-F.:/]*) return 1 ;;
	esac
	ip=${a%%/*}
	len=''
	[ "$a" != "$ip" ] && len=${a#*/}
	case "$ip" in
		*:*)
			if [ -n "$len" ]; then
				case "$len" in ''|*[!0-9]*) return 1 ;; esac
				[ "$len" -le 128 ] || return 1
			fi
			return 0 ;;
		*)
			if [ -n "$len" ]; then
				case "$len" in ''|*[!0-9]*) return 1 ;; esac
				[ "$len" -le 32 ] || return 1
			fi
			local IFS=. rest
			set -- $ip
			[ $# -eq 4 ] || return 1
			for o in "$@"; do
				case "$o" in ''|*[!0-9]*) return 1 ;; esac
				[ "$o" -le 255 ] || return 1
			done
			return 0 ;;
	esac
}

# ---------------------------------------------------------- SPF traversal --
GROUP_FILE=''
VISITED=''

emit() {
	local a=$1
	if valid_addr "$a"; then
		printf '%s\n' "$a" >> "$GROUP_FILE"
	else
		warn "ignoring unparsable address from SPF: $a"
	fi
}

emit_host() {                                   # a:/mx: target -> its addresses
	local h=$1 r
	h=${h%%/*}
	[ -n "$h" ] || return 0
	for r in $(dig +short +time="$DNS_TIMEOUT" +tries="$DNS_TRIES" A "$h" 2>/dev/null) \
	         $(dig +short +time="$DNS_TIMEOUT" +tries="$DNS_TRIES" AAAA "$h" 2>/dev/null); do
		case "$r" in *[!0-9a-fA-F.:]*) continue ;; esac
		emit "$r"
	done
}

emit_mx() {
	local d=$1 m
	for m in $(dig +short +time="$DNS_TIMEOUT" +tries="$DNS_TRIES" MX "$d" 2>/dev/null | awk '{print $2}'); do
		emit_host "${m%.}"
	done
}

# resolve_spf <domain> <depth> <follow>
resolve_spf() {
	local domain=$1 depth=$2 follow=$3 rec tok

	[ "$depth" -ge "$MAX_DEPTH" ] && { warn "SPF too deep at $domain"; return 0; }
	case " $VISITED " in *" $domain "*) return 0 ;; esac      # include: loops
	VISITED="$VISITED $domain"

	# A long SPF record arrives as several quoted strings on one line; they
	# concatenate with nothing between them, so the quote pairs go first.
	rec=$(dig +short +time="$DNS_TIMEOUT" +tries="$DNS_TRIES" TXT "$domain" 2>/dev/null \
	      | sed -e 's/" "//g' -e 's/"//g' | grep -m1 -i '^v=spf1')
	[ -n "$rec" ] || { warn "no SPF record at $domain"; return 0; }

	for tok in $rec; do
		case "$tok" in
			v=spf1|V=spf1)                continue ;;
			all|+all|-all|'~all'|'?all')  continue ;;
			ip4:*|+ip4:*|IP4:*)           emit "${tok#*:}" ;;
			ip6:*|+ip6:*|IP6:*)           emit "${tok#*:}" ;;
			include:*|+include:*)
				[ "$follow" = no ] || resolve_spf "${tok#*:}" $((depth + 1)) "$follow" ;;
			redirect=*)
				[ "$follow" = no ] || resolve_spf "${tok#*=}" $((depth + 1)) "$follow" ;;
			a:*|+a:*)                     emit_host "${tok#*:}" ;;
			mx:*|+mx:*)                   emit_mx "${tok#*:}" ;;
			a|+a)                         emit_host "$domain" ;;
			mx|+mx)                       emit_mx "$domain" ;;
			# ptr: and exists: answer per-query and cannot be enumerated
			*) continue ;;
		esac
	done
}

# ------------------------------------------------------------- generation --
BODY="$TMPDIR_RUN/body"
COUNTS="$TMPDIR_RUN/counts"
SA_META="$TMPDIR_RUN/sa.meta"
: > "$COUNTS"
: > "$SA_META"
SEEN_ALL="$TMPDIR_RUN/seen"
: > "$BODY"
: > "$SEEN_ALL"

# An address already filed under an earlier provider is not repeated: the old
# file listed 54.240.0.0/18 and 199.127.232.0/22 twice each, which tells a
# reader nothing and makes the groups add up to more than the list.
parse_ini() {
	awk '
		function trim(s) { sub(/^[ \t]+/, "", s); sub(/[ \t\r]+$/, "", s); return s }
		function out() { printf "%s\037%s\037%s\037%s\037%s\037%s\037%s\n", sec, label, spf, follow, static, dflt, sascore }
		/^[ \t]*[;#]/ { next }
		/^[ \t]*\[/ {
			if (sec != "") out()
			line = $0; sub(/^[ \t]*\[/, "", line); sub(/\].*$/, "", line)
			sec = trim(line); label = ""; spf = ""; follow = ""; static = ""; dflt = ""; sascore = ""
			next
		}
		{
			p = index($0, "=")
			if (p == 0 || sec == "") next
			k = trim(substr($0, 1, p - 1)); v = trim(substr($0, p + 1))
			if      (k == "label")    label   = v
			else if (k == "spf")      spf     = v
			else if (k == "follow")   follow  = v
			else if (k == "static")   static  = v
			else if (k == "default")  dflt    = v
			else if (k == "sa_score") sascore = v
		}
		END { if (sec != "") out() }
	' "$1"
}

# Switching a provider off has to mean its addresses are not exempt, full stop.
# It did not: PayPal's SPF include:s sendgrid.net, so with SendGrid switched
# off every SendGrid netblock was still exempt, filed under PayPal -- 21 of the
# rejections in a 6-day sample would have been let through. The providers that
# are off are therefore resolved too, and their addresses subtracted from every
# enabled provider's group. An IP list cannot tell "SendGrid sending as PayPal"
# apart from "SendGrid sending as anyone else", so the only honest reading of
# "off" is that the range goes.
DENY="$TMPDIR_RUN/deny"
: > "$DENY"
while IFS=$'\037' read -r key label spf follow static dflt sascore; do
	[ -n "$key" ] || continue
	provider_is_on "$key" "$dflt" && continue
	GROUP_FILE="$TMPDIR_RUN/deny.$key"
	: > "$GROUP_FILE"
	VISITED=''
	IFS=, read -r -a dseeds <<< "$spf"
	for s in "${dseeds[@]}"; do
		s=$(echo "$s" | tr -d ' \t')
		[ -n "$s" ] && resolve_spf "$s" 0 "${follow:-yes}"
	done
	for s in $static; do
		emit "$s"
	done
	sort -u "$GROUP_FILE" >> "$DENY"
done < <(parse_ini "$INI")
sort -u "$DENY" -o "$DENY"
DENY_N=$(grep -c . "$DENY" 2>/dev/null); DENY_N=${DENY_N:-0}
[ "$DENY_N" -gt 0 ] && say "  ($DENY_N addresses belong to providers that are off and will be excluded)"

ESP_COUNT=0
SUPPRESSED=0
PROV_TOTAL=0
PROV_OK=0
PROV_EMPTY=''


while IFS=$'\037' read -r key label spf follow static dflt sascore; do
	[ -n "$key" ] || continue
	[ -n "$label" ] || label=$key
	PROV_TOTAL=$((PROV_TOTAL + 1))

	if ! provider_is_on "$key" "$dflt"; then
		say "  - $label (off)"
		continue
	fi

	GROUP_FILE="$TMPDIR_RUN/g.$key"
	: > "$GROUP_FILE"
	VISITED=''

	IFS=, read -r -a seeds <<< "$spf"
	for s in "${seeds[@]}"; do
		s=$(echo "$s" | tr -d ' \t')
		[ -n "$s" ] && resolve_spf "$s" 0 "${follow:-yes}"
	done
	for s in $static; do
		emit "$s"
	done

	# dedupe inside the group, then drop anything an earlier provider owned
	sort -u "$GROUP_FILE" -o "$GROUP_FILE"
	if [ "$DENY_N" -gt 0 ]; then
		before=$(grep -c . "$GROUP_FILE" 2>/dev/null); before=${before:-0}
		comm -23 "$GROUP_FILE" "$DENY" > "$TMPDIR_RUN/k.$key" && mv "$TMPDIR_RUN/k.$key" "$GROUP_FILE"
		after=$(grep -c . "$GROUP_FILE" 2>/dev/null); after=${after:-0}
		if [ "$before" -gt "$after" ]; then
			SUPPRESSED=$((SUPPRESSED + before - after))
			say "      ($((before - after)) of $label's addresses belong to a provider that is off)"
		fi
	fi
	uniq_file="$TMPDIR_RUN/u.$key"
	comm -23 "$GROUP_FILE" "$SEEN_ALL" > "$uniq_file" 2>/dev/null || cp "$GROUP_FILE" "$uniq_file"

	n=$(grep -c . "$uniq_file" 2>/dev/null)
	n=${n:-0}
	printf '%s %s\n' "$key" "$n" >> "$COUNTS"
	if [ "$n" -eq 0 ]; then
		say "  - $label (no addresses)"
		PROV_EMPTY="$PROV_EMPTY $label"
		continue
	fi

	{
		echo ""
		echo "# === $label === ($n)"
		sort -V "$uniq_file"
	} >> "$BODY"
	# the SpamAssassin rules are built from exactly the same groups
	printf '%s\t%s\t%s\n' "$key" "$label" "$sascore" >> "$SA_META"

	sort -u "$SEEN_ALL" "$uniq_file" -o "$SEEN_ALL"
	ESP_COUNT=$((ESP_COUNT + n))
	PROV_OK=$((PROV_OK + 1))
	say "  - $label: $n"
done < <(parse_ini "$INI")

# ----------------------------------------------------------- local extras --
# One entry per line, an optional "# note" kept alongside it -- exim ignores
# everything from a # to end of line in a list file, so the note survives into
# the live list instead of being lost the way the old file's were.
EXTRA_FILE="$TMPDIR_RUN/extra"
: > "$EXTRA_FILE"
EXTRA_COUNT=0

if [ -n "$EXTRA_RAW" ]; then
	while IFS= read -r line; do
		line=${line%$'\r'}
		addr=${line%%#*}
		note=''
		[ "$line" != "$addr" ] && note=${line#*#}
		addr=$(echo "$addr" | tr -d ' \t')
		[ -n "$addr" ] || continue
		if valid_addr "$addr"; then
			note=$(echo "$note" | sed -e 's/^[ \t]*//' -e 's/[ \t]*$//')
			if [ -n "$note" ]; then
				printf '%s  # %s\n' "$addr" "$note" >> "$EXTRA_FILE"
			else
				printf '%s\n' "$addr" >> "$EXTRA_FILE"
			fi
			EXTRA_COUNT=$((EXTRA_COUNT + 1))
		else
			warn "ignoring invalid extra address: $addr"
		fi
	done <<< "$EXTRA_RAW"
fi

# --------------------------------------------------------------- assemble --
STAMP="$(date '+%Y-%m-%d %H:%M:%S %z') on $(hostname -f 2>/dev/null || hostname)"
PROV_COUNTS=$(tr '\n' ',' < "$COUNTS" | tr ' ' '=' | sed 's/,$//')

NEW="$TMPDIR_RUN/skiprblhosts"
{
	echo "# ==========================================================================="
	echo "#  $OUT -- senders exempt from DNS blocklist (RBL) checks"
	echo "#"
	echo "#  GENERATED FILE. Every run of build-skiprbl.sh replaces it."
	echo "#  Hand edits are lost -- change the inputs instead:"
	echo "#"
	echo "#    providers  $INI"
	echo "#    extra IPs  Reqad panel -> Email -> Exim -> Blocklists (RBL)${EXTRA_IN:+, $EXTRA_IN}"
	echo "#"
	echo "#  Generated $STAMP"
	echo "#  esp-entries: $ESP_COUNT"
	echo "#  providers: $PROV_OK of $PROV_TOTAL"
	echo "#  suppressed: $SUPPRESSED (claimed by a provider that is switched off)"
	echo "#  provider-counts: $PROV_COUNTS"
	echo "# ==========================================================================="
	cat "$BODY"
	if [ "$EXTRA_COUNT" -gt 0 ]; then
		echo ""
		echo "# === Local additions === ($EXTRA_COUNT)"
		cat "$EXTRA_FILE"
	fi
	echo ""
} > "$NEW"

if [ "$DRY" = 1 ] && [ "$PRINT_SA" = 0 ]; then
	cat "$NEW"
	exit 0
fi

# ------------------------------------------------------ SpamAssassin rules --
# SpamAssassin has no "relay IP is in this list" test, so every range becomes a
# regex over the X-Spam-Relays-External pseudo-header, anchored on its FIRST
# entry: the address that connected to this server, written by our own MTA.
# Every later hop comes from Received: headers the sender wrote, and anyone
# can prepend a fake Google hop.
#
# Relays-External rather than Relays-Untrusted: a range that also sits in
# trusted_networks drops out of Relays-Untrusted altogether (that is what
# "trusted" means), and the rule would silently never fire.
sa_generate() {
	cat > "$TMPDIR_RUN/sa.pl" <<'PERL'
use strict;
use warnings;
use Socket qw(inet_pton inet_ntop AF_INET AF_INET6);

my ($meta, $gdir, $local, $local_score, $sadir, $sabase) = @ARGV;
my @D = (0 .. 9, 'a' .. 'f');
my $fail = 0;

# -- numeric range -> regex, numbers written without leading zeros ----------
sub dclass {
	my ($a, $b, $base) = @_;
	return $D[$a] if $a == $b;
	return ($base == 10 ? '\d' : '[0-9a-f]') if $a == 0 && $b == $base - 1;
	return '[' . join('', @D[$a .. $b]) . ']';
}
sub anyd {
	my ($k, $base) = @_;
	return '' unless $k;
	my $c = $base == 10 ? '\d' : '[0-9a-f]';
	return $k == 1 ? $c : "$c\{$k\}";
}
sub fixed {                          # equal-length digit arrays, any digits
	my ($lo, $hi, $base) = @_;
	my $n = @$lo;
	return dclass($lo->[0], $hi->[0], $base) if $n == 1;
	my @lr = @$lo[1 .. $n - 1];
	my @hr = @$hi[1 .. $n - 1];
	return $D[$lo->[0]] . fixed(\@lr, \@hr, $base) if $lo->[0] == $hi->[0];
	my $allmin = !grep { $_ != 0 } @lr;
	my $allmax = !grep { $_ != $base - 1 } @hr;
	my ($s, $e) = ($lo->[0], $hi->[0]);
	my (@p, $tail);
	if (!$allmin) { push @p, $D[$s] . fixed(\@lr, [($base - 1) x ($n - 1)], $base); $s++ }
	if (!$allmax) { $tail = $D[$e] . fixed([(0) x ($n - 1)], \@hr, $base); $e-- }
	push @p, dclass($s, $e, $base) . anyd($n - 1, $base) if $s <= $e;
	push @p, $tail if defined $tail;
	return @p == 1 ? $p[0] : '(?:' . join('|', @p) . ')';
}
sub digits {
	my ($v, $len, $base) = @_;
	my @d;
	for (1 .. $len) { unshift @d, $v % $base; $v = int($v / $base) }
	return \@d;
}
sub range_rx {
	my ($lo, $hi, $base) = @_;
	my @p;
	for (my $L = 1; ; $L++) {
		my $min = $L == 1 ? 0 : $base ** ($L - 1);
		last if $min > $hi;
		my $max = $base ** $L - 1;
		my $a = $lo > $min ? $lo : $min;
		my $b = $hi < $max ? $hi : $max;
		next if $a > $b;
		push @p, fixed(digits($a, $L, $base), digits($b, $L, $base), $base);
	}
	return @p == 1 ? $p[0] : '(?:' . join('|', @p) . ')';
}

# -- CIDR -> regex over the address as an MTA writes it ---------------------
sub v4_rx {
	my ($bin, $len) = @_;
	my @o = unpack 'C4', $bin;
	my @r;
	for my $i (0 .. 3) {
		my $bits = $len - 8 * $i;
		if    ($bits >= 8) { push @r, $o[$i] }
		elsif ($bits <= 0) { push @r, '\d{1,3}' }
		else {
			my $m = 0xff >> $bits;
			my $lo = $o[$i] & ~$m & 0xff;
			push @r, range_rx($lo, $lo | $m, 10);
		}
	}
	return join '\.', @r;
}

# IPv6 arrives compressed (RFC 5952: lowercase, no leading zeros, the longest
# run of zero groups as "::"), so the prefix can be spelled two ways: every
# constrained group written out, or a "::" that starts inside the prefix --
# which is only possible where the prefix allows those groups to be zero.
sub v6_rx {
	my ($bin, $len) = @_;
	return '[0-9a-f:.]+' if $len == 0;
	my @g = unpack 'n8', $bin;
	my $L = int(($len + 15) / 16) - 1;          # last group the prefix touches
	my (@lo, @hi);
	for my $i (0 .. $L) {
		my $bits = $len - 16 * $i;
		my $m = $bits >= 16 ? 0 : (0xffff >> $bits);
		$lo[$i] = $g[$i] & ~$m & 0xffff;
		$hi[$i] = $lo[$i] | $m;
	}
	my @grx = map { range_rx($lo[$_], $hi[$_], 16) } 0 .. $L;
	my @alt = (join(':', @grx) . ($L < 7 ? ':[0-9a-f:.]*' : ''));
	my $h = '[0-9a-f]{1,4}';
	for my $s (0 .. $L) {
		next if grep { $lo[$_] != 0 } $s .. $L;
		my $pre = $s ? join(':', @grx[0 .. $s - 1]) : '';
		my $t = 7 - $L;                     # groups left after the "::"
		my $trail = $t <= 0 ? '' : $t == 1 ? "(?:$h)?" : "(?:$h(?::$h){0," . ($t - 1) . "})?";
		push @alt, $pre . '::' . $trail;
	}
	return @alt == 1 ? $alt[0] : '(?:' . join('|', @alt) . ')';
}

sub parse_cidr {
	my ($c) = @_;
	my ($ip, $len) = split m{/}, $c, 2;
	my $v6 = $ip =~ /:/ ? 1 : 0;
	my $bin = inet_pton($v6 ? AF_INET6 : AF_INET, $ip);
	return unless defined $bin;
	my $max = $v6 ? 128 : 32;
	$len = $max unless defined $len && length $len;
	return unless $len =~ /^\d+$/ && $len <= $max;
	return ($bin, $len, $v6);
}

sub mask_bytes {                            # 1 bits for the network part
	my ($len, $bytes) = @_;
	return pack('B*', ('1' x $len) . ('0' x ($bytes * 8 - $len)));
}
sub step {                                  # packed address +/- 1, undef on wrap
	my ($bin, $d) = @_;
	my @b = unpack 'C*', $bin;
	for (my $i = $#b; $i >= 0; $i--) {
		my $v = $b[$i] + $d;
		if ($v >= 0 && $v <= 255) { $b[$i] = $v; return pack 'C*', @b }
		$b[$i] = $v < 0 ? 255 : 0;
	}
	return undef;
}

# A regex nobody checked is a rule that silently misses, or matches half the
# internet. Every range is proven on its first and last address, a few inside
# it (including ones whose zero groups compress into "::") and both
# neighbours just outside -- before anything is installed.
sub selftest {
	my ($cidr, $rx, $bin, $len, $v6) = @_;
	my $fam = $v6 ? AF_INET6 : AF_INET;
	my $n = $v6 ? 16 : 4;
	my $m = mask_bytes($len, $n);
	my $lo = $bin & $m;
	my $hi = $lo | ~$m;
	my @in = ($lo, $hi);
	push @in, step($lo, 1) if $len < $n * 8;
	for (1 .. 3) {
		my $r = pack 'C*', map { int rand 256 } 1 .. $n;
		push @in, $lo | ($r & ~$m);
		my $sparse = "\0" x ($n - 1) . chr(1 + int rand 200);
		push @in, $lo | ($sparse & ~$m);
	}
	my $re = qr/^(?:$rx) /;
	for my $a (@in) {
		my $t = inet_ntop($fam, $a) . ' ';
		next if $t =~ $re;
		warn "self-test: $cidr does not match $t\n"; $fail = 1;
	}
	for my $a (step($lo, -1), step($hi, 1)) {
		next unless defined $a;
		my $t = inet_ntop($fam, $a) . ' ';
		next unless $t =~ $re;
		warn "self-test: $cidr wrongly matches $t\n"; $fail = 1;
	}
}

sub score_of {
	my ($v, $what) = @_;
	$v = '' unless defined $v;
	$v =~ s/^\s+|\s+$//g;
	$v =~ s/^["']|["']$//g;
	return 0 if $v eq '';
	unless ($v =~ /^[+-]?(\d+\.?\d*|\.\d+)$/) { warn "sa_score for $what is not a number ($v), using 0\n"; return 0 }
	if ($v > 0)  { warn "sa_score for $what is positive ($v), using 0\n"; return 0 }
	if ($v < -3) { warn "sa_score for $what is below -3 ($v), using -3\n"; return -3 }
	return $v + 0;
}

my (@rules, @provnets);
sub build_rule {
	my ($name, $desc, $score, $cidrs, $provider) = @_;
	my @rx;
	for my $c (@$cidrs) {
		my ($bin, $len, $v6) = parse_cidr($c) or do { warn "skipping unparsable range $c\n"; next };
		my $rx = $v6 ? v6_rx($bin, $len) : v4_rx($bin, $len);
		selftest($c, $rx, $bin, $len, $v6);
		push @rx, $rx;
		push @provnets, [$c, $bin, $len, $v6, $desc] if $provider;
	}
	return unless @rx;
	push @rules, [$name, $desc, $score, join('|', @rx)];
}

open my $mf, '<', $meta or die "$meta: $!\n";
while (my $l = <$mf>) {
	chomp $l;
	my ($key, $label, $score) = split /\t/, $l, 3;
	open my $gf, '<', "$gdir/u.$key" or next;
	my @c = grep { length } map { s/\s+//gr } <$gf>;
	my $name = uc $key; $name =~ s/[^A-Z0-9_]/_/g;
	build_rule("REQAD_ESP_$name", $label, score_of($score, $key), \@c, 1);
}
if (-s $local) {
	open my $lf, '<', $local or die "$local: $!\n";
	my @c = grep { length } map { my $x = $_; $x =~ s/#.*//; $x =~ s/\s+//g; $x } <$lf>;
	build_rule('REQAD_ESP_LOCAL', 'local additions', score_of($local_score, 'local additions'), \@c, 0);
}
exit 1 if $fail;

for my $r (@rules) {
	my ($name, $desc, $score, $rx) = @$r;
	my $sub = "__$name";
	my $cond = $sub;
	# an operator's own entry inside a provider range must not score twice
	if ($name eq 'REQAD_ESP_LOCAL' && @rules > 1) {
		$cond = "($sub && !(" . join(' || ', map { "__$_->[0]" } grep { $_->[0] ne $name } @rules) . '))';
	}
	my $d = "Relay is $desc";
	$d = substr($d, 0, 50) if length $d > 50;
	$score = -0.001 if $score == 0;       # 0 would disable the rule entirely
	print "\n";
	print "header   $sub  X-Spam-Relays-External =~ /^\\[ ip=(?:$rx) /\n";
	print "meta     $name  $cond\n";
	print "describe $name  $d\n";
	print "score    $name  $score\n";
	print "tflags   $name  nice noautolearn\n";
}

# -- trusted_networks / internal_networks that swallow provider ranges -------
# Checked here because the result of getting it wrong is invisible: nothing
# errors, the rules just never fire (internal) or SpamAssassin skips its own
# RBL tests on that provider and hands out ALL_TRUSTED (trusted).
if (-d $sadir) {
	for my $f (sort glob("$sadir/*.cf")) {
		next if $f eq "$sadir/$sabase";
		open my $cf, '<', $f or next;
		my %hit;
		while (my $l = <$cf>) {
			next unless $l =~ /^\s*(trusted_networks|internal_networks)\s+(.*)$/;
			my $dir = $1;
			for my $tok (split ' ', $2) {
				last if $tok =~ /^#/;
				$tok =~ s/^!//;
				my ($tb, $tl, $t6) = parse_cidr($tok) or next;
				for my $p (@provnets) {
					my ($pc, $pb, $pl, $p6, $pdesc) = @$p;
					next if $t6 != $p6;
					my $bits = $tl < $pl ? $tl : $pl;
					next unless substr(unpack('B*', $tb), 0, $bits) eq substr(unpack('B*', $pb), 0, $bits);
					$hit{$dir}{$pdesc}++;
				}
			}
		}
		for my $dir (sort keys %hit) {
			my @p = sort keys %{ $hit{$dir} };
			my $what = $dir eq 'internal_networks'
				? 'those relays count as internal, so their REQAD_ESP rules cannot fire'
				: 'SpamAssassin skips its RBL tests on them and awards ALL_TRUSTED';
			warn "$f: $dir covers ranges of " . join(', ', @p) . " -- $what\n";
		}
	}
}
exit 0
PERL
	"$PERL" "$TMPDIR_RUN/sa.pl" "$SA_META" "$TMPDIR_RUN" "$EXTRA_FILE" "$SA_LOCAL_SCORE" \
		"$(dirname "$SA_OUT")" "$(basename "$SA_OUT")"
}

SA_NEW="$TMPDIR_RUN/reqad-esp.cf"
if [ "$DO_SA" = 1 ]; then
	{
		echo "# ==========================================================================="
		echo "#  $SA_OUT -- score mail handed over directly by a known email provider"
		echo "#"
		echo "#  GENERATED FILE. Every run of build-skiprbl.sh replaces it."
		echo "#  Hand edits are lost -- change sa_score in $INI"
		echo "#  (override a score in local.cf if you would rather not touch the catalogue)."
		echo "#"
		echo "#  Each REQAD_ESP_<PROVIDER> rule matches only the first entry of"
		echo "#  X-Spam-Relays-External: the address that connected to this server."
		echo "#"
		echo "#  Generated $STAMP"
		echo "#  esp-entries: $ESP_COUNT"
		echo "#  provider-counts: $PROV_COUNTS"
		echo "# ==========================================================================="
		sa_generate || exit 1
		echo ""
	} > "$SA_NEW" || die "SpamAssassin rules could not be generated"
	if [ "$PRINT_SA" = 1 ]; then
		cat "$SA_NEW"
		exit 0
	fi
fi

# ------------------------------------------------------------ shrink guard --
# The guard is here to catch DNS failing, so it must not fire when the list
# shrank because somebody switched providers off on purpose. The baseline is
# therefore the previous run's count for the providers that are still ON --
# turning six of them off drops the total without moving the baseline at all.
BASEFILE=''
if [ "$DO_EXIM" = 1 ] && [ -r "$OUT" ]; then
	BASEFILE=$OUT
elif [ "$DO_SA" = 1 ] && [ -r "$SA_OUT" ]; then
	BASEFILE=$SA_OUT
fi
if [ -n "$BASEFILE" ] && [ "$FORCE" = 0 ]; then
	PREVLINE=$(sed -n 's/^#[[:space:]]*provider-counts:[[:space:]]*//p' "$BASEFILE" | head -1)
	if [ -n "${PREVLINE:-}" ]; then
		BASE=0
		WENT_QUIET=''
		for pair in $(echo "$PREVLINE" | tr ',' ' '); do
			pk=${pair%%=*}
			pv=${pair#*=}
			case "$pv" in ''|*[!0-9]*) continue ;; esac
			# only what is still enabled counts towards the baseline
			awk -v k="$pk" '$1 == k { found = 1 } END { exit !found }' "$COUNTS" || continue
			BASE=$((BASE + pv))
			if [ "$pv" -gt 0 ]; then
				nowv=$(awk -v k="$pk" '$1 == k { print $2 }' "$COUNTS")
				[ "${nowv:-0}" -eq 0 ] && WENT_QUIET="$WENT_QUIET $pk"
			fi
		done
		# A provider that lost every address is worth saying out loud, but it is
		# not on its own a reason to refuse: reordering the catalogue moves
		# addresses between providers without losing any.
		[ -n "$WENT_QUIET" ] && warn "provider(s) returned no addresses this run:$WENT_QUIET"
		if [ "$BASE" -gt 0 ]; then
			FLOOR=$((BASE * SHRINK_FLOOR_PCT / 100))
			if [ "$ESP_COUNT" -lt "$FLOOR" ]; then
				warn "refusing to install: $ESP_COUNT addresses from the providers that are on, expected around $BASE (floor $FLOOR)."
				warn "switching providers off does not trigger this -- it usually means DNS is failing."
				warn "re-run with --force if the drop is real."
				exit 2
			fi
		fi
	else
		# pre-provider-counts file: fall back to the old whole-list comparison
		PREV=$(sed -n 's/^#[[:space:]]*esp-entries:[[:space:]]*\([0-9]\{1,\}\).*/\1/p' "$BASEFILE" | head -1)
		if [ -n "${PREV:-}" ] && [ "$PREV" -gt 0 ] && [ "$ESP_COUNT" -lt $((PREV * SHRINK_FLOOR_PCT / 100)) ]; then
			warn "refusing to install: $ESP_COUNT provider addresses, was $PREV."
			warn "re-run with --force if the drop is real."
			exit 2
		fi
	fi
fi

if [ "$ESP_COUNT" -eq 0 ] && [ "$EXTRA_COUNT" -eq 0 ]; then
	warn "refusing to install an empty list"
	exit 2
fi

# --------------------------------------------------------------- install ---
# the timestamp line always differs, so it is left out of the comparison
same_file() {
	[ -r "$2" ] && diff -q <(grep -v '^#  Generated ' "$1") <(grep -v '^#  Generated ' "$2") >/dev/null 2>&1
}

RC=0

if [ "$DO_EXIM" = 1 ]; then
	if same_file "$NEW" "$OUT"; then
		say "no change to $OUT ($ESP_COUNT provider addresses, $EXTRA_COUNT local)"
	else
		install -m 0644 -o root -g root "$NEW" "$OUT" || die "cannot write $OUT"
		say "wrote $OUT: $ESP_COUNT provider addresses from $PROV_OK providers, $EXTRA_COUNT local"
		[ -n "$PROV_EMPTY" ] && warn "no addresses found for:$PROV_EMPTY"
	fi
fi

# spamd reads its rules once, at start; the distribution's own sa-update job
# restarts it the same way. A stopped spamd picks the file up when it starts.
restart_spamd() {
	if [ -x /scripts/restartsrv_spamd ]; then
		/scripts/restartsrv_spamd >/dev/null 2>&1
		return
	fi
	command -v systemctl >/dev/null 2>&1 || return 0
	local u
	for u in spamassassin.service spamd.service; do
		if systemctl --quiet is-active "$u" 2>/dev/null; then
			systemctl try-restart "$u"
			return
		fi
	done
	return 0
}

if [ "$DO_SA" = 1 ]; then
	if same_file "$SA_NEW" "$SA_OUT"; then
		say "no change to $SA_OUT"
	else
		# Lint the candidate on top of the live configuration BEFORE it goes in:
		# a broken rule file in the site directory breaks every scan on the box.
		if ! LINT=$("$SA_BIN" --lint --cf="include $SA_NEW" 2>&1); then
			if "$SA_BIN" --lint >/dev/null 2>&1; then
				warn "generated SpamAssassin rules fail --lint; $SA_OUT left unchanged:"
			else
				warn "the SpamAssassin configuration already fails --lint; not adding $SA_OUT until that is fixed:"
			fi
			printf '%s\n' "$LINT" | tail -5 >&2
			exit 1
		fi
		install -m 0644 -o root -g root "$SA_NEW" "$SA_OUT" || die "cannot write $SA_OUT"
		RULES=$(grep -c '^meta ' "$SA_OUT")
		if restart_spamd; then
			say "wrote $SA_OUT: $RULES rules, spamd restarted"
		else
			warn "wrote $SA_OUT ($RULES rules) but spamd did not restart -- restart it by hand"
			RC=1
		fi
	fi
fi

exit $RC
