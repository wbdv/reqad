#!/bin/bash
# Backup exclusions — the ONE place that turns the panel's rules (the
# backup_excludes table, db/1036.sql) into what tar and find understand.
# backup.sh, backup_remote.sh (through backup.sh) and the panel's Exclusions
# tab all go through here, so the size preview and the real backup can never
# disagree about what a pattern matches.
#
# Usage (root, except `check`):
#   backup_excludes.sh check   <pattern>
#       validate + normalise a path pattern; prints the stored form, exit 1
#       with the reason on stderr when it is refused
#   backup_excludes.sh build   <user> <scope> <outdir> <record> [--no-excludes]
#       <outdir>/.tar-excludes   tar patterns, one per line (use with --anchored)
#       <outdir>/.tar-flags      extra tar flags, one per line
#       <record>                 what is left out: kind<TAB>path<TAB>detail
#       --no-excludes keeps only the mounted-filesystem exclusions
#   backup_excludes.sh dbs     <user> <scope>    excluded database names
#   backup_excludes.sh mounts  <user>            rel<TAB>fstype<TAB>source
#   backup_excludes.sh preview <user>            what every rule matches (panel)
#   backup_excludes.sh suggest <user>            things worth excluding (panel)
#
# Pattern rules (gitignore-style, always relative to the home directory):
#   node_modules          no slash          any depth
#   /tmp                  leading slash     only <home>/tmp
#   public_html/cache     slash inside      anchored at the home directory
#   **/wp-content/cache   leading **/       any depth
#   *, ?, [...] are wildcards and * also crosses '/', as in tar and find -path.
#
# WHY the patterns are anchored and prefixed with <user>/: backup.sh and
# backup_remote.sh tar the staged metadata tree (backup-<user>/meta, the
# database dumps, ...) in the SAME tar run as the home directory. tar's own
# default is unanchored, so a rule such as `*` or `meta` would have matched
# the staged tree too and produced an archive restore.sh cannot rebuild an
# account from. Every pattern written here starts with "<user>/" and tar runs
# with --anchored, which can only ever match /home/<user> members.

export LC_ALL=C

REQAD=/usr/local/reqad
DB_FILE="${REQAD}/db/reqad.db"
# an array: the tab separator would be word-split away in a plain string
SQLITE=( /usr/bin/sqlite3 -init /dev/null -batch -noheader -separator $'\t' )
TAG_SIG='Signature: 8a477f597d28d172789f06886806bc55'
NICE='nice -n 19 ionice -c 3'

die() { echo "backup_excludes: $*" >&2; exit 1; }

valid_user()  { [[ "$1" =~ ^[a-z][a-z0-9]{1,15}$ ]]; }
valid_scope() { [ "$1" = nightly ] || [ "$1" = manual ]; }
setting()     { "${SQLITE[@]}" "${DB_FILE}" "SELECT value FROM settings WHERE name='$1'" 2>/dev/null; }

# ─── pattern handling ───────────────────────────────────────────────────────
# normalise <pattern>: prints "<anchored 0|1><TAB><body><TAB><stored form>" or
# fails with the reason on stderr.
normalise() {
	local p="$1" anchored body comp
	# leading/trailing blanks are typing accidents, not file names
	p="${p#"${p%%[![:space:]]*}"}"; p="${p%"${p##*[![:space:]]}"}"
	[ -n "${p}" ]          || { echo "empty pattern" >&2; return 1; }
	[ ${#p} -le 255 ]      || { echo "pattern too long" >&2; return 1; }
	# no quotes, $, `, \, |, # or control characters: the pattern is written
	# into a tar exclude file and shown in the panel, and none of those has a
	# use in a path rule
	local re='^[][A-Za-z0-9._@+,=%~ *?/!-]+$'
	[[ "${p}" =~ ${re} ]] \
		|| { echo "only letters, digits, space and . _ - @ + , = % ~ / * ? [ ] ! are allowed" >&2; return 1; }
	while [[ "${p}" == *//* ]]; do p="${p//\/\//\/}"; done
	[ "${p}" != / ] || { echo "that would exclude the whole home directory" >&2; return 1; }
	p="${p%/}"
	if [[ "${p}" == /* ]]; then
		anchored=1; body="${p#/}"
	elif [[ "${p}" == '**/'* ]]; then
		anchored=0; body="${p#\*\*/}"
	elif [[ "${p}" == */* ]]; then
		anchored=1; body="${p}"
	else
		anchored=0; body="${p}"
	fi
	# ** means the same as * here (tar's * already crosses '/')
	while [[ "${body}" == *'**'* ]]; do body="${body//\*\*/*}"; done
	[ -n "${body}" ] || { echo "empty pattern" >&2; return 1; }
	local parts
	IFS=/ read -ra parts <<< "${body}"
	for comp in "${parts[@]}"; do
		case "${comp}" in
			''|.|..) echo "'.' and '..' are not allowed in a pattern" >&2; return 1 ;;
		esac
	done
	# nothing but wildcards and slashes matches (nearly) everything
	[[ "${body}" =~ [^*?/] ]] || { echo "that would exclude the whole home directory" >&2; return 1; }
	local stored
	if [ ${anchored} -eq 1 ]; then
		if [[ "${body}" == */* ]]; then stored="${body}"; else stored="/${body}"; fi
	else
		if [[ "${body}" == */* ]]; then stored="**/${body}"; else stored="${body}"; fi
	fi
	printf '%s\t%s\t%s\n' "${anchored}" "${body}" "${stored}"
}

# tar_patterns <user> <stored pattern>: the --anchored tar patterns, one per
# line, rooted at "<user>/" (find -path uses the same strings under /home/).
tar_patterns() {
	local n anchored body stored
	n=$(normalise "$2" 2>/dev/null) || return 1
	IFS=$'\t' read -r anchored body stored <<< "${n}"
	echo "$1/${body}"
	[ "${anchored}" -eq 1 ] || echo "$1/*/${body}"
}

# a literal path as a pattern: escape what tar/find would read as wildcards
glob_escape() { printf '%s' "$1" | sed 's/[][*?\\]/\\&/g'; }

# path rules for a user: "<id><TAB><pattern><TAB><scope>" — the account's own
# first, then the server-wide ones. $2 = nightly|manual|any
path_rules() {
	local sc=''
	[ "$2" = any ] || sc="AND scope IN ('both','$2')"
	"${SQLITE[@]}" "${DB_FILE}" "SELECT id, pattern, scope FROM backup_excludes
		WHERE kind='path' AND user IN ('$1','*') ${sc}
		ORDER BY (user='*'), id" 2>/dev/null
}

# filesystems mounted strictly inside /home/<user>: "rel<TAB>fstype<TAB>source".
# Read from the mount table, never by stat()ing the mount point — a dead sshfs
# can hang a stat for minutes.
mounts() {
	local home t f s
	home=$(readlink -f "/home/$1") || return 0
	[ -n "${home}" ] || return 0
	findmnt -rn -o TARGET,FSTYPE,SOURCE 2>/dev/null | while read -r t f s; do
		t=$(printf '%b' "${t}"); s=$(printf '%b' "${s}")    # findmnt -r writes a space as \x20
		[[ "${t}" == "${home}/"* ]] || continue
		printf '%s\t%s\t%s\n' "${t#"${home}"/}" "${f}" "${s}"
	done | sort -u
}

# drop every path that sits inside another one on the list (stdin, sorted)
outermost() {
	awk 'BEGIN{last=""} { if (last != "" && index($0, last "/") == 1) next; print; last=$0 }'
}

# ─── commands ───────────────────────────────────────────────────────────────
cmd="$1"; shift
case "${cmd}" in

check)
	n=$(normalise "$1") || exit 1
	printf '%s\n' "$(cut -f3 <<< "${n}")"
	;;

mounts)
	valid_user "$1" || die "invalid user"
	mounts "$1"
	;;

dbs)
	U="$1"; SC="$2"
	valid_user "${U}" || die "invalid user"
	valid_scope "${SC}" || die "scope must be nightly or manual"
	"${SQLITE[@]}" "${DB_FILE}" "SELECT pattern FROM backup_excludes WHERE kind='db' AND user='${U}' AND scope IN ('both','${SC}')" 2>/dev/null \
		| grep -E '^[A-Za-z0-9_]{1,64}$'
	;;

build)
	U="$1"; SC="$2"; OUT="$3"; REC="$4"; NOEX="$5"
	valid_user "${U}" || die "invalid user"
	valid_scope "${SC}" || die "scope must be nightly or manual"
	[ -d "${OUT}" ] || die "no such directory: ${OUT}"
	PAT="${OUT}/.tar-excludes"; FLAGS="${OUT}/.tar-flags"
	: > "${PAT}"; : > "${FLAGS}"
	LINES=()
	PRUNE=()      # find -path patterns for what is already excluded

	if [ "$(setting backup-exclude-mounts)" != 0 ]; then
		while IFS=$'\t' read -r rel fs src; do
			[ -n "${rel}" ] || continue
			e="${U}/$(glob_escape "${rel}")"
			echo "${e}" >> "${PAT}"
			PRUNE+=( "/home/${e}" )
			LINES+=( "$(printf 'mount\t%s\t%s %s' "${rel}" "${fs}" "${src}")" )
		done < <(mounts "${U}")
	fi

	if [ "${NOEX}" != --no-excludes ]; then
		while IFS=$'\t' read -r id pat scope; do
			[ -n "${pat}" ] || continue
			tp=$(tar_patterns "${U}" "${pat}") || { echo "backup_excludes: skipping invalid rule #${id}: ${pat}" >&2; continue; }
			while read -r e; do
				echo "${e}" >> "${PAT}"
				PRUNE+=( "/home/${e}" )
			done <<< "${tp}"
			LINES+=( "$(printf 'rule\t%s\t%s' "${pat}" "${scope}")" )
		done < <(path_rules "${U}" "${SC}")

		# the two marker files: .nobackup is on unless switched off ('0'),
		# CACHEDIR.TAG is off unless switched on ('1')
		MX=()
		if [ "$(setting backup-exclude-markers)" != 0 ]; then
			echo '--exclude-tag=.nobackup' >> "${FLAGS}"
			MX=( '(' -name .nobackup -type f -printf 'T\t%h\n' ')' )
		fi
		if [ "$(setting backup-exclude-caches)" = 1 ]; then
			echo '--exclude-caches' >> "${FLAGS}"
			[ ${#MX[@]} -gt 0 ] && MX+=( -o )
			MX+=( '(' -name CACHEDIR.TAG -type f -printf 'C\t%h\n' ')' )
		fi

		# Which directories the markers will empty. tar decides that on its own
		# while it walks, so this is only for the record (restore.sh lists it):
		# one -xdev walk that skips what the rules and mounts already remove.
		if [ ${#MX[@]} -gt 0 ] && [ -d "/home/${U}" ]; then
			FX=()
			if [ ${#PRUNE[@]} -gt 0 ]; then
				FX=( '(' )
				for p in "${PRUNE[@]}"; do FX+=( -path "${p}" -o ); done
				FX[${#FX[@]}-1]=')'
				FX+=( -prune -o )
			fi
			while IFS=$'\t' read -r k d; do
				if [ "${k}" = C ]; then
					[ "$(head -c ${#TAG_SIG} "${d}/CACHEDIR.TAG" 2>/dev/null)" = "${TAG_SIG}" ] || continue
					LINES+=( "$(printf 'cachedir\t%s\tCACHEDIR.TAG' "${d#/home/${U}/}")" )
				else
					LINES+=( "$(printf 'marker\t%s\t.nobackup' "${d#/home/${U}/}")" )
				fi
			done < <(${NICE} find "/home/${U}" -xdev "${FX[@]}" "${MX[@]}" 2>/dev/null \
				| sort -t$'\t' -k2 | awk -F'\t' 'BEGIN{last=""} { if (last != "" && index($2, last "/") == 1) next; print; last=$2 }')
		fi
	fi

	if [ ${#LINES[@]} -gt 0 ]; then
		{
			echo "# Left out of this backup (paths relative to the home directory)."
			echo "# kind<TAB>what<TAB>detail — rule: a pattern from the panel's backup"
			echo "# exclusions; mount: a filesystem mounted there; marker: a folder holding"
			echo "# a .nobackup file; cachedir: CACHEDIR.TAG; db: a database restored empty"
			printf '%s\n' "${LINES[@]}"
		} > "${REC}"
	fi
	;;

preview)
	U="$1"
	valid_user "${U}" || die "invalid user"
	HOME_DIR="/home/${U}"
	[ -d "${HOME_DIR}" ] || die "no home directory for ${U}"
	TMP=$(mktemp -d /var/tmp/reqad-bxprev.XXXXXX) || die "mktemp failed"
	trap 'rm -rf "${TMP}"' EXIT

	echo "home	$(${NICE} du -skx "${HOME_DIR}" 2>/dev/null | cut -f1)"
	while IFS=$'\t' read -r rel fs src; do
		printf 'mount\t%s\t%s\t%s\n' "${rel}" "${fs}" "${src}"
	done < <(mounts "${U}")

	# One walk. Each rule gets its own -path group; the first rule that matches
	# an entry prints it and prunes it, so nothing is counted twice. What no
	# rule took is then checked for the two kinds of marker.
	FX=()
	IDS=()
	while IFS=$'\t' read -r id pat scope; do
		tp=$(tar_patterns "${U}" "${pat}") || continue
		G=( '(' )
		while read -r e; do G+=( -path "/home/${e}" -o ); done <<< "${tp}"
		G[${#G[@]}-1]=')'
		FX+=( "${G[@]}" -printf "R${id}\t%y\t%k\t%p\n" -prune -o )
		IDS+=( "${id}" )
	done < <(path_rules "${U}" any)

	${NICE} timeout 900 find "${HOME_DIR}" -xdev "${FX[@]}" \
		\( -name .nobackup -type f -printf 'T\td\t0\t%h\n' \) -o \
		\( -name CACHEDIR.TAG -type f -printf 'C\td\t0\t%h\n' \) 2>/dev/null > "${TMP}/hits"

	for id in "${IDS[@]}"; do
		awk -F'\t' -v k="R${id}" '$1==k' "${TMP}/hits" > "${TMP}/r"
		n=$(wc -l < "${TMP}/r")
		[ "${n}" -gt 0 ] || { printf 'rule\t%s\t0\t0\t\n' "${id}"; continue; }
		fkb=$(awk -F'\t' '$2!="d"{s+=$3} END{print s+0}' "${TMP}/r")
		awk -F'\t' '$2=="d"{print $4}' "${TMP}/r" | tr '\n' '\0' > "${TMP}/d"
		dkb=0
		[ -s "${TMP}/d" ] && dkb=$(${NICE} du -skxc --files0-from="${TMP}/d" 2>/dev/null | tail -1 | cut -f1)
		samples=$(cut -f4 "${TMP}/r" | head -3 | sed "s|^${HOME_DIR}/||" | paste -sd '|')
		printf 'rule\t%s\t%s\t%s\t%s\n' "${id}" "$(( fkb + ${dkb:-0} ))" "${n}" "${samples}"
	done

	# markers: only the outermost one counts (tar never sees one inside another)
	for k in T C; do
		awk -F'\t' -v k="${k}" '$1==k{print $4}' "${TMP}/hits" | sort | outermost | while read -r d; do
			if [ "${k}" = C ]; then
				[ "$(head -c ${#TAG_SIG} "${d}/CACHEDIR.TAG" 2>/dev/null)" = "${TAG_SIG}" ] || continue
				kind=cachedir
			else
				kind=marker
			fi
			printf '%s\t%s\t%s\n' "${kind}" "${d#${HOME_DIR}/}" "$(${NICE} du -skx "${d}" 2>/dev/null | cut -f1)"
		done
	done
	;;

suggest)
	U="$1"
	valid_user "${U}" || die "invalid user"
	HOME_DIR="/home/${U}"
	[ -d "${HOME_DIR}" ] || die "no home directory for ${U}"
	TMP=$(mktemp -d /var/tmp/reqad-bxsugg.XXXXXX) || die "mktemp failed"
	trap 'rm -rf "${TMP}"' EXIT
	MIN_KB=1024

	# what the rules already remove is not suggested again (mounts are -xdev)
	FX=()
	while IFS=$'\t' read -r id pat scope; do
		tp=$(tar_patterns "${U}" "${pat}") || continue
		while read -r e; do FX+=( -path "/home/${e}" -o ); done <<< "${tp}"
	done < <(path_rules "${U}" any)
	if [ ${#FX[@]} -gt 0 ]; then
		FX[${#FX[@]}-1]=')'
		FX=( '(' "${FX[@]}" -prune -o )
	fi

	# Mail is skipped: nothing in a maildir is regenerable, and it is usually
	# the bulk of the files, so walking it would only make this slower.
	${NICE} timeout 600 find "${HOME_DIR}" -xdev "${FX[@]}" \
		-path "${HOME_DIR}/mail" -prune -o \
		\( -path "${HOME_DIR}/.cache" -o -path "${HOME_DIR}/.npm" \) -type d -printf 'D\t%p\n' -prune -o \
		-type d -name node_modules -printf 'N\t%p\n' -prune -o \
		-type d \( -path '*/wp-content/cache' -o -path '*/wp-content/litespeed' \
		        -o -path '*/wp-content/updraft' -o -path '*/wp-content/ai1wm-backups' \
		        -o -path '*/wp-content/backups-dup-lite' -o -path '*/wp-content/backups-dup-pro' \
		        -o -path '*/wp-content/wpvivid_backup' -o -path '*/wp-content/backup-db' \) -printf 'W\t%p\n' -prune -o \
		-type f \( -name '*.zip' -o -name '*.tar' -o -name '*.tar.gz' -o -name '*.tgz' -o -name '*.tar.bz2' \
		        -o -name '*.wpress' -o -name '*.jpa' -o -name '*.sql' -o -name '*.sql.gz' -o -name '*.bak' \) \
		        -size +50M -printf 'A\t%k\t%p\n' -o \
		-type f -name error_log -printf 'E\t%k\t%p\n' -o \
		-type f -regex '.*/core\.[0-9]+' -printf 'K\t%k\t%p\n' \
		2>/dev/null > "${TMP}/hits"

	# a concrete path as a stored pattern (anchored), or nothing when it holds
	# characters a pattern may not
	as_pattern() {
		local rel="${1#${HOME_DIR}/}" s
		[[ "${rel}" == */* ]] || rel="/${rel}"
		s=$(normalise "${rel}" 2>/dev/null | cut -f3) || return 1
		[[ "${rel}" =~ [][*?] ]] && return 1
		echo "${s}"
	}
	{
		# one rule for every node_modules, sized together
		awk -F'\t' '$1=="N"{print $2}' "${TMP}/hits" | tr '\n' '\0' > "${TMP}/nm"
		if [ -s "${TMP}/nm" ]; then
			n=$(tr -cd '\0' < "${TMP}/nm" | wc -c)
			kb=$(${NICE} du -skxc --files0-from="${TMP}/nm" 2>/dev/null | tail -1 | cut -f1)
			printf 'node_modules\t%s\t%s\tnpm packages (reinstall with npm install)\n' "${kb:-0}" "${n}"
		fi
		awk -F'\t' '$1=="W"||$1=="D"{print $1"\t"$2}' "${TMP}/hits" | while IFS=$'\t' read -r k p; do
			s=$(as_pattern "${p}") || continue
			kb=$(${NICE} du -skx "${p}" 2>/dev/null | cut -f1)
			case "${p##*/}" in
				cache|litespeed) label='WordPress page cache (rebuilt on demand)' ;;
				.cache|.npm)     label='tool cache (rebuilt on demand)' ;;
				*)               label='WordPress backup plugin archives (a backup inside the backup)' ;;
			esac
			printf '%s\t%s\t1\t%s\n' "${s}" "${kb:-0}" "${label}"
		done
		awk -F'\t' '$1=="A"' "${TMP}/hits" | while IFS=$'\t' read -r k kb p; do
			s=$(as_pattern "${p}") || continue
			printf '%s\t%s\t1\t%s\n' "${s}" "${kb}" 'large archive or dump file'
		done
		awk -F'\t' '$1=="E"{n++; s+=$2} END{if(n) printf "error_log\t%d\t%d\tPHP error logs\n", s, n}' "${TMP}/hits"
		awk -F'\t' '$1=="K"' "${TMP}/hits" | while IFS=$'\t' read -r k kb p; do
			s=$(as_pattern "${p}") || continue
			printf '%s\t%s\t1\t%s\n' "${s}" "${kb}" 'core dump'
		done
	} | awk -F'\t' -v min="${MIN_KB}" '$2>=min' | sort -t$'\t' -k2,2nr
	;;

*)
	sed -n '2,32p' "$0"
	exit 2
	;;
esac
