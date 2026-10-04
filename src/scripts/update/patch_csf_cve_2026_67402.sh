#!/bin/bash
# Apply the CVE-2026-67402 / CPANEL-55649 hardening to an already-installed csf.
#
# New installs get a csf tarball that already carries this (we host csf.tgz
# ourselves and repacked it), so this exists only to bring existing servers up.
#
# The bug, in one line: csf.deny/allow/ignore/tempban/tempallow are read back an
# entry per physical line, and untrusted text - a PTR record, a GeoIP name, a
# comment relayed by a cluster peer - reaches the comment field of those
# entries, so a line separator in it appends a whole attacker-chosen rule rather
# than corrupting the one being written. See scripts/patch_csf_cve.pl for the
# full write-up; the actual edits live there so that this and the tarball repack
# cannot drift apart.
#
# Safety properties, in order of how much they matter:
#
#   - The patcher is anchored on exact file content, not on a version string. It
#     validates all four files before writing any of them, so an unrecognised
#     csf (locally modified, or a version this was not built against) leaves the
#     tree untouched rather than half-patched.
#   - Every file is backed up before the run, and restored if the post-patch
#     syntax check fails. A csf that does not compile means no firewall
#     management and a dead lfd, which is worse than the bug being fixed.
#   - Idempotent: a second run reports "already patched" and does nothing.
#
# No-op if csf was never installed.

# CSF_ROOT exists so this script can be exercised against a copy of a csf tree
# rather than only against the live one - the patcher is well covered by its own
# tests, but the backup/verify/rollback logic here is worth being able to run.
# Unset in normal use, which is what post_reqad_install.sh does.
CSF_ROOT="${CSF_ROOT:-}"

CSF_BIN=$CSF_ROOT/usr/sbin/csf
LFD_BIN=$CSF_ROOT/usr/sbin/lfd
CSF_LIB=$CSF_ROOT/usr/local/csf/lib/ConfigServer
CSF_TPL=$CSF_ROOT/usr/local/csf/tpl
PATCHER=/usr/local/reqad/scripts/patch_csf_cve.pl

# ── Preconditions ────────────────────────────────────────────────────────────
if [ ! -x "$CSF_BIN" ]; then
	# csf is optional - a server installed without it has nothing to do here
	exit 0
fi

# Tested with -f and invoked through the interpreter rather than relying on the
# exec bit: this package has lost one in %install before, and the failure mode
# there would be this security fix quietly reporting "missing" forever.
if [ ! -f "$PATCHER" ]; then
	echo "csf CVE-2026-67402: $PATCHER missing, skipping"
	exit 0
fi

# Compile-checked set. Templates are Apache/LiteSpeed config, not Perl, so they
# are backed up and restored with the rest but never handed to "perl -c".
FILES=("$CSF_BIN" "$LFD_BIN" "$CSF_LIB/Slurp.pm" "$CSF_LIB/LookUpIP.pm")

TPL_FILES=()
for t in apache.http.txt apache.https.txt litespeed.http.txt litespeed.https.txt; do
	[ -f "$CSF_TPL/$t" ] && TPL_FILES+=("$CSF_TPL/$t")
done
for f in "${FILES[@]}"; do
	if [ ! -f "$f" ]; then
		echo "csf CVE-2026-67402: $f missing, skipping (unrecognised csf layout)"
		exit 0
	fi
done

# Already done? Checked before taking a backup, so a no-op run leaves no litter.
#
# Both halves are tested, not just csf: an earlier release of this script fixed
# only the Perl files, so a server that ran it has the marker in csf while its
# Messenger templates still carry the /usr/bin CGI alias. Testing csf alone
# would skip those servers forever - which is the half that is the actual CVE.
CSF_DONE=0
TPL_DONE=0
grep -q 'CVE-2026-67402' "$CSF_BIN" 2>/dev/null && CSF_DONE=1
if [ ${#TPL_FILES[@]} -eq 0 ]; then
	TPL_DONE=1                                   # no templates present: nothing to do
elif ! grep -rlq 'ScriptAlias /local-bin /usr/bin' "${TPL_FILES[@]}" 2>/dev/null \
   && ! grep -rlq 'allowSymbolLink 1' "${TPL_FILES[@]}" 2>/dev/null; then
	TPL_DONE=1
fi
if [ "$CSF_DONE" = 1 ] && [ "$TPL_DONE" = 1 ]; then
	exit 0
fi

echo -n "Patch csf for CVE-2026-67402 "

# ── Back up, then patch ──────────────────────────────────────────────────────
BKP="${CSF_ROOT:-/root}/csf-cve-2026-67402.bkp-$(date +%F-%H%M%S)"
mkdir -p "$BKP" || { echo "[ FAILED: cannot create $BKP ]"; exit 1; }
for f in "${FILES[@]}" "${TPL_FILES[@]}"; do
	cp -a "$f" "$BKP/$(basename "$f")" || { echo "[ FAILED: cannot back up $f ]"; exit 1; }
done

# Which files compile *before* we touch anything. Without this baseline the
# check below blames this patch for a fault that predates it - a csf missing an
# optional Perl dependency already fails "perl -c", and rolling back over that
# would leave the box unpatched while reporting a failure it cannot act on. The
# question worth asking is not "does it compile" but "did we break it".
declare -A COMPILED_BEFORE
for f in "${FILES[@]}"; do
	perl -c "$f" > /dev/null 2>&1 && COMPILED_BEFORE["$f"]=1
done

OUT=$(perl "$PATCHER" "$CSF_BIN" "$LFD_BIN" "$CSF_LIB/Slurp.pm" "$CSF_LIB/LookUpIP.pm" "$CSF_TPL" 2>&1)
RC=$?

if [ $RC -ne 0 ]; then
	# The patcher writes nothing when it refuses, so there is nothing to undo
	echo "[ SKIPPED ]"
	echo "$OUT" | sed 's/^/  /'
	# Nothing was patched, so the backup has nothing to protect. Removed by the
	# exact name generated above, never a caller-supplied path.
	case "$BKP" in */csf-cve-2026-67402.bkp-*) rm -rf "$BKP" ;; esac
	exit 0
fi

# ── Verify, and roll back if the result does not compile ─────────────────────
BROKEN=""
CHECKED=0
for f in "${FILES[@]}"; do
	[ -n "${COMPILED_BEFORE[$f]:-}" ] || continue
	CHECKED=$((CHECKED + 1))
	perl -c "$f" > /dev/null 2>&1 || BROKEN="$BROKEN $(basename "$f")"
done

if [ -n "$BROKEN" ]; then
	for f in "${FILES[@]}" "${TPL_FILES[@]}"; do
		cp -a "$BKP/$(basename "$f")" "$f"
	done
	echo "[ FAILED - ROLLED BACK ]"
	echo "  these did not compile after patching:$BROKEN"
	echo "  originals restored from $BKP"
	exit 1
fi

# ── Restart lfd so the patched modules are actually loaded ───────────────────
# Only lfd holds the code in memory; csf itself is re-exec'd per command. The
# firewall rules are untouched by this patch, so there is no need for a full
# "csf -ra" and its brief rule reload.
if [ -z "$CSF_ROOT" ] && systemctl is-active --quiet lfd 2>/dev/null; then
	systemctl restart lfd > /dev/null 2>&1 || "$CSF_BIN" --lfd restart > /dev/null 2>&1
fi

echo "[ OK ]"
echo "  patched: csf, lfd, Slurp.pm, LookUpIP.pm${TPL_FILES:+, Messenger templates} (originals: $BKP)"
if [ "$CHECKED" -eq 0 ]; then
	echo "  note: none of the four compiled before patching either, so the syntax"
	echo "        check could not verify anything - this csf was already broken"
fi
exit 0
