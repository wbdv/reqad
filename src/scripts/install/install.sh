#!/bin/bash
#
# Reqad meta-installer.
#
#   curl -sSL https://repo.reqad.net/install.sh | bash
#   curl -sSL https://repo.reqad.net/install.sh | bash -s -- --template apache_modphp --php 8.4
#
# Detects the operating system, asks for the install options, then hands over to
# the matching install script (install-el.sh) with the answers exported. It holds
# no install logic of its own.
#
# Pure bash, with the form drawn through terminfo (tput, from the ncurses package
# that a stock EL8/EL9 already carries). The screen is painted once and only the
# lines that change are rewritten, so the form does not flicker on a slow or
# remote terminal. Falls back to running non-interactively if terminfo has no
# cursor addressing (TERM unset or dumb).

VERSION='0.4.0 - Jul 28, 2026'

REPO="${REQAD_REPO:-https://repo.reqad.net}"
REPO="${REPO%/}"

# The backend script runs here: it writes ./install_reqad.log and unpacks csf in
# its working directory, which must not be wherever the user happened to be.
WORKDIR='/var/tmp/reqad-install'

WHITE='\033[1;37m'
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
GREY='\033[0;90m'
BOLD='\033[1m'
NC='\033[0m'

TEMPLATES=(nginx_php-fpm apache_modphp)
TEMPLATE_LABELS=('nginx + php-fpm' 'apache + mod_php')
PHP_VERSIONS=(7.4 8.2 8.3 8.4 8.5)

# Defaults. TIMEZONE defaults to whatever the machine already uses.
TEMPLATE='nginx_php-fpm'
PHP_VERSION='8.3'
WITH_EMAIL=0
SSH_PORT='22'
TIMEZONE=''
SKIP_UPDATE=0
DRY_RUN=0
INTERACTIVE=1
SCRIPT_OVERRIDE=''

die() {
    printf "\n ${RED}ERROR:${NC} %b\n\n" "$1" >&2
    exit 1
}

usage() {
    cat <<EOF
Reqad installer ${VERSION}

Usage:
  install.sh [options]

Run with no options for the interactive installer. Passing any option below
runs non-interactively, with defaults for anything not specified.

Options:
  --template NAME     web server stack: nginx_php-fpm | apache_modphp  (default nginx_php-fpm)
  --php VERSION       PHP version: ${PHP_VERSIONS[*]}                 (default 8.3)
  --email             install the email stack (exim/dovecot)           (default off)
  --no-email          do not install the email stack
  --ssh-port PORT     SSH port                                         (default 22)
  --timezone TZ       timezone, e.g. Europe/Bucharest                  (default: keep current)
  --skip-update       skip the initial dnf update and package install
  -y, --yes           non-interactive, accept all defaults
  --dry-run           print what would run, change nothing
  --script PATH|URL   override the install script location (testing)
  -h, --help          this help
  -V, --version       print version

Examples:
  install.sh
  install.sh --template apache_modphp --php 8.4 --email
  install.sh -y --ssh-port 22 --timezone Europe/Bucharest
EOF
}

index_of() {
    local needle=$1; shift
    local i=0 item
    for item in "$@"; do
        [ "$item" == "$needle" ] && { echo "$i"; return; }
        i=$(( i + 1 ))
    done
    echo 0
}

in_list() {
    local needle=$1; shift
    local item
    for item in "$@"; do
        [ "$item" == "$needle" ] && return 0
    done
    return 1
}

valid_port() {
    case $1 in
        ''|*[!0-9]*) return 1 ;;
    esac
    [ "$1" -ge 1 ] && [ "$1" -le 65535 ]
}

valid_timezone() {
    [ -n "$1" ] && [ -f "/usr/share/zoneinfo/$1" ]
}

current_timezone() {
    local tz
    tz=$(timedatectl show --property=Timezone --value 2>/dev/null)
    if [ -z "$tz" ] && [ -L /etc/localtime ]; then
        tz=$(readlink /etc/localtime)
        tz=${tz#*/zoneinfo/}
    fi
    [ -n "$tz" ] || tz='UTC'
    echo "$tz"
}

#=[ argument parsing ]=================================================================================================

parse_args() {
    local saw_config=0

    while [ $# -gt 0 ]; do
        # Accept --flag=value as well as --flag value.
        case $1 in
            --*=*)
                set -- "${1%%=*}" "${1#*=}" "${@:2}"
                ;;
        esac

        case $1 in
            -h|--help)    usage; exit 0 ;;
            -V|--version) echo "Reqad installer ${VERSION}"; exit 0 ;;

            --template)
                [ $# -ge 2 ] || die "--template needs a value"
                in_list "$2" "${TEMPLATES[@]}" \
                    || die "invalid --template '$2'\n        valid: ${TEMPLATES[*]}"
                TEMPLATE=$2; saw_config=1; shift 2 ;;

            --php)
                [ $# -ge 2 ] || die "--php needs a value"
                in_list "$2" "${PHP_VERSIONS[@]}" \
                    || die "invalid --php '$2'\n        valid: ${PHP_VERSIONS[*]}"
                PHP_VERSION=$2; saw_config=1; shift 2 ;;

            --email)      WITH_EMAIL=1; saw_config=1; shift ;;
            --no-email)   WITH_EMAIL=0; saw_config=1; shift ;;

            --ssh-port)
                [ $# -ge 2 ] || die "--ssh-port needs a value"
                valid_port "$2" || die "invalid --ssh-port '$2' (1-65535)"
                SSH_PORT=$2; saw_config=1; shift 2 ;;

            --timezone)
                [ $# -ge 2 ] || die "--timezone needs a value"
                valid_timezone "$2" || die "unknown timezone '$2'"
                TIMEZONE=$2; saw_config=1; shift 2 ;;

            --skip-update) SKIP_UPDATE=1; saw_config=1; shift ;;
            -y|--yes)      saw_config=1; shift ;;
            --dry-run)     DRY_RUN=1; shift ;;

            --script)
                [ $# -ge 2 ] || die "--script needs a value"
                SCRIPT_OVERRIDE=$2; shift 2 ;;

            *) die "unknown option '$1' (try --help)" ;;
        esac
    done

    [ "$saw_config" -eq 1 ] && INTERACTIVE=0
    return 0
}

#=[ platform detection ]===============================================================================================

detect_platform() {
    OS_ID=''; OS_VERSION_ID=''; OS_PRETTY=''; OS_LIKE=''
    if [ -r /etc/os-release ]; then
        # Read it in a subshell: /etc/os-release defines VERSION, NAME and
        # friends, and sourcing it here would clobber this script's own $VERSION.
        eval "$(
            # shellcheck disable=SC1091
            . /etc/os-release
            printf 'OS_ID=%q OS_VERSION_ID=%q OS_PRETTY=%q OS_LIKE=%q\n' \
                "${ID,,}" "${VERSION_ID}" "${PRETTY_NAME}" "${ID_LIKE,,}"
        )"
    fi
    OS_MAJOR=${OS_VERSION_ID%%.*}
    [ -n "$OS_PRETTY" ] || OS_PRETTY="${OS_ID:-unknown} ${OS_VERSION_ID}"

    ARCH=$(uname -m)

    BACKEND=''
    UNSUPPORTED=''

    case $ARCH in
        x86_64|amd64) ;;
        *) UNSUPPORTED="architecture ${ARCH} is not supported - Reqad requires x86_64"; return ;;
    esac

    local family=''
    case $OS_ID in
        rocky|almalinux|rhel|centos|ol|oracle|fedora) family=el ;;
        debian|ubuntu) family=debian ;;
        *)
            case $OS_LIKE in
                *rhel*|*fedora*) family=el ;;
                *debian*)        family=debian ;;
            esac ;;
    esac

    case $family in
        el)
            case $OS_MAJOR in
                8|9)  BACKEND='install-el.sh' ;;
                10)   UNSUPPORTED='Enterprise Linux 10 is not supported yet' ;;
                *)    UNSUPPORTED="Enterprise Linux ${OS_MAJOR:-?} is not supported" ;;
            esac ;;
        debian)
            UNSUPPORTED='Debian and Ubuntu are not supported yet' ;;
        *)
            UNSUPPORTED="unrecognised operating system '${OS_PRETTY}'" ;;
    esac
}

gather_facts() {
    FACT_HOST=$(hostname 2>/dev/null)
    FACT_IP=$(ip address show 2>/dev/null | grep 'scope global' | grep 'inet ' | head -n 1 | awk '{print $2}' | awk -F/ '{print $1}')
    FACT_VCORES=$(grep -c '^processor' /proc/cpuinfo 2>/dev/null)
    FACT_MEM=$(free -hmw --si 2>/dev/null | grep 'Mem:' | awk '{print $2}')
}

check_repo() {
    local code
    if command -v curl >/dev/null 2>&1; then
        code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 15 -I "${REPO}/install-el.sh" 2>/dev/null)
    elif command -v wget >/dev/null 2>&1; then
        code=$(wget -q -S --spider --timeout=15 "${REPO}/install-el.sh" 2>&1 | awk '/HTTP\//{c=$2} END{print c}')
    else
        die "neither curl nor wget is available"
    fi

    case $code in
        200) return 0 ;;
        401|403)
            die "${REPO} refused this machine (HTTP ${code}).
        The repository is restricted to registered servers - ask for this
        server's IP address to be allowlisted, then run the installer again." ;;
        000|'')
            die "cannot reach ${REPO} - check the network and DNS" ;;
        *)
            die "${REPO} returned HTTP ${code} for install-el.sh" ;;
    esac
}

# reqad_version -> REQAD_VERSION. Best effort and entirely decorative: it is
# shown next to the wordmark and skipped silently if anything goes wrong, so it
# must never delay or block the install. Prefers an already-installed package,
# otherwise asks the repository metadata what it is offering.
reqad_version() {
    REQAD_VERSION=''

    if command -v rpm >/dev/null 2>&1; then
        REQAD_VERSION=$(rpm -q --qf '%{VERSION}' reqad 2>/dev/null)
        case $REQAD_VERSION in
            [0-9]*) return ;;
            *) REQAD_VERSION='' ;;
        esac
    fi

    command -v curl >/dev/null 2>&1 || return
    command -v gzip >/dev/null 2>&1 || return
    [ -n "$OS_MAJOR" ] || return

    local base="${REPO}/el${OS_MAJOR}/RPMS/x86_64"
    local href
    href=$(curl -fsS --max-time 4 "${base}/repodata/repomd.xml" 2>/dev/null \
           | tr '<' '\n' | grep -m1 -o 'location href="[^"]*primary\.xml\.gz"' \
           | sed 's/.*href="//; s/"$//') || return
    [ -n "$href" ] || return

    local newest
    newest=$(curl -fsS --max-time 6 "${base}/${href}" 2>/dev/null | gzip -dc 2>/dev/null \
             | grep -o 'reqad-[0-9][0-9.]*-[0-9]*\.el[0-9]*\.noarch\.rpm' \
             | sort -V | tail -n 1) || return
    [ -n "$newest" ] || return

    newest=${newest#reqad-}
    REQAD_VERSION=${newest%%-*}
}

#=[ terminal helpers - terminfo / ncurses ]============================================================================
#
# Same form as install.sh, drawn differently. install.sh clears the whole screen
# (\033[H\033[J) on every keypress; here the screen is painted once and then only
# the lines that actually changed are rewritten, addressed through terminfo.
#
# Two rules keep it fast, and they matter more than they look:
#
#   1. Every terminfo capability is resolved once at startup and cached in a
#      variable. tput forks a process; calling it per keypress would cost more
#      than the escape codes it replaces.
#   2. Nothing in the redraw path uses command substitution. `x=$(f)` forks a
#      subshell, and at ~45 of them per frame the form took 158 ms to repaint -
#      visibly laggy. The helpers below return values by assigning to a global
#      (R_*) instead of echoing, which keeps a redraw entirely inside bash.

shopt -s extglob

TTY='/dev/tty'

# Actually open the terminal rather than testing its permission bits: /dev/tty
# is world read/write, so `[ -r /dev/tty ]` is true even when the process has no
# controlling terminal (cron, packer, `bash < script`) - and the form would then
# block forever waiting for a keypress that cannot arrive.
has_tty() { ( exec 3<>"$TTY" ) 2>/dev/null; }

UI_READY=0

# cap <name> [args...] - a terminfo capability, or empty when unsupported.
# Startup only; never called from the redraw path.
cap() { tput "$@" 2>/dev/null || true; }

# The box is drawn with line-drawing characters, which are only safe to measure
# and print when the locale is UTF-8: under LC_ALL=C, bash counts "│" as three
# characters and every padding calculation goes wrong. Switch to a UTF-8 locale
# if one exists, otherwise fall back to an ASCII box.
ui_charset() {
    case ${LC_ALL:-${LC_CTYPE:-$LANG}} in
        *[Uu][Tt][Ff]*8*) UI_UTF8=1; return ;;
    esac
    local l
    for l in C.UTF-8 C.utf8 en_US.UTF-8 en_US.utf8; do
        if locale -a 2>/dev/null | grep -qix "${l/UTF-8/utf8}"; then
            export LC_ALL=$l
            UI_UTF8=1
            return
        fi
    done
    UI_UTF8=0
}

ui_glyphs() {
    if [ "$UI_UTF8" -eq 1 ]; then
        G_TL='┌'; G_TR='┐'; G_BL='└'; G_BR='┘'
        G_H='─';  G_V='│';  G_ML='├'; G_MR='┤'
        G_ARROW='▸'; G_ARROW2='◂'; G_DOT='·'; G_UD='↑↓'; G_LR='←→'; G_ENTER='⏎'; G_CROSS='✗'
    else
        G_TL='+'; G_TR='+'; G_BL='+'; G_BR='+'
        G_H='-';  G_V='|';  G_ML='+'; G_MR='+'
        G_ARROW='>'; G_ARROW2='<'; G_DOT='-'; G_UD='up/dn'; G_LR='left/right'; G_ENTER='enter'; G_CROSS='x'
    fi

    # Wordmark, figlet "slant": italic by construction. Rendered bold so the
    # strokes fill out. Rows print independently and left-aligned, so they do
    # not need to be the same length.
    ART=(
'    ____                       __'
'   / __ \___  ____ _____ _____/ /'
'  / /_/ / _ \/ __ `/ __ `/ __  / '
' / _, _/  __/ /_/ / /_/ / /_/ /  '
'/_/ |_|\___/\__, /\__,_/\__,_/   '
'              /_/                '
    )
    ART_ROWS=${#ART[@]}
}

# Most terminals that announce themselves as plain "xterm" are really 256-colour
# capable; a bare TERM=xterm costs the grey selection bar and the logo gradient.
# Upgrade only when a matching terminfo entry exists and actually reports 256 -
# never for TERM=linux, which really is an 8-colour console.
ui_term() {
    [ -n "$REQAD_TERM" ] && { export TERM=$REQAD_TERM; return; }

    local colors; colors=$(cap colors); [ -n "$colors" ] || colors=0
    [ "$colors" -ge 256 ] && return

    local cand=''
    case $TERM in
        xterm)         cand=xterm-256color ;;
        screen)        cand=screen-256color ;;
        tmux)          cand=tmux-256color ;;
        rxvt-unicode)  cand=rxvt-unicode-256color ;;
        *)  # A terminal advertising COLORTERM is modern regardless of its name.
            [ -n "$COLORTERM" ] && cand="${TERM}-256color" ;;
    esac
    [ -n "$cand" ] || return

    local got
    got=$(TERM=$cand tput colors 2>/dev/null) || return
    [ "${got:-0}" -ge 256 ] && export TERM=$cand
}

ui_caps() {
    ui_term
    T_SMCUP=$(cap smcup); T_RMCUP=$(cap rmcup)
    T_CIVIS=$(cap civis); T_CNORM=$(cap cnorm)
    T_EL=$(cap el)
    T_CLEAR=$(cap clear)
    T_RESET=$(cap sgr0);  T_BOLD=$(cap bold)

    local colors
    colors=$(cap colors); [ -n "$colors" ] || colors=0

    if [ "$colors" -ge 8 ]; then
        T_RED=$(cap setaf 1);  T_GREEN=$(cap setaf 2); T_YELLOW=$(cap setaf 3)
        T_WHITE=$(cap setaf 7)
    else
        T_RED=''; T_GREEN=''; T_YELLOW=''; T_WHITE=''
    fi

    # Dark grey needs a 256-colour palette. On an 8-colour terminal there is no
    # such thing, so the focused line is marked with reverse video instead -
    # `tput setab 236` there emits a bogus ESC[4236m that prints as garbage.
    if [ "$colors" -ge 256 ]; then
        T_BG_SEL=$(cap setab 233)
        T_GREY=$(cap setaf 244)
        T_DIM=$(cap setaf 240)
        T_FG_SEL=$(cap setaf 255)
        T_SEL_ON="$(cap setab 22)$(cap setaf 231)"   # active chip: white on green
        # Brackets are structure, not content: keep them well behind the values.
        T_BRACKET=$(cap setaf 237)          # around the chosen value
        T_BRACKET_OFF=$(cap setaf 233)      # around the others: barely there
        T_BRACKET_SEL=$(cap setaf 239)      # legible against the selection bar
        T_HELP=$(cap setaf 237)             # footer hint line
        T_VAL=$(cap setaf 252)                       # active value, unfocused row
        T_VAL_OFF=$(cap setaf 242)                   # inactive value
        # Start button: green, always, so it reads as the thing you press.
        T_BTN="$(cap setab 22)$(cap setaf 252)"
        # Same green as the active chip; focus reads from the brighter text
        # and the arrows, not from a second shade.
        T_BTN_SEL="$(cap setab 22)$(cap setaf 231)"
        # Wordmark gradient, from the logo: #0174C3 blue down to #003A70 navy.
        T_LOGO1=$(cap setaf 39); T_LOGO2=$(cap setaf 33)
        T_LOGO3=$(cap setaf 32); T_LOGO4=$(cap setaf 25)
        T_AMBER=$(cap setaf 214)
    elif [ "$colors" -ge 8 ]; then
        T_BG_SEL=$(cap rev)
        T_GREY=$T_WHITE
        T_DIM=$T_WHITE
        T_FG_SEL=''
        T_SEL_ON="$(cap bold)$(cap setaf 3)"
        T_BRACKET=$T_DIM; T_BRACKET_OFF=$T_DIM; T_BRACKET_SEL=$T_DIM; T_HELP=$T_DIM
        T_VAL=$T_WHITE;   T_VAL_OFF=$T_DIM
        T_BTN="$(cap setab 2)$(cap setaf 0)"
        T_BTN_SEL="$(cap setab 2)$(cap setaf 7)$(cap bold)"
        T_LOGO1=$(cap setaf 4); T_LOGO2=$T_LOGO1
        T_LOGO3=$T_LOGO1;       T_LOGO4=$T_LOGO1
        T_AMBER=$(cap setaf 3)
    else
        T_BG_SEL=$(cap rev)
        T_GREY=''; T_DIM=''; T_FG_SEL=''; T_SEL_ON=$(cap bold)
        T_BRACKET=''; T_BRACKET_OFF=''; T_BRACKET_SEL=''; T_VAL=''; T_VAL_OFF=''; T_HELP=''
        T_BTN=$(cap rev); T_BTN_SEL="$(cap rev)$(cap bold)"
        T_LOGO1=''; T_LOGO2=''; T_LOGO3=''; T_LOGO4=''; T_AMBER=''
    fi

    UI_LINES=$(cap lines); [ -n "$UI_LINES" ] || UI_LINES=24
    UI_COLS=$(cap cols);   [ -n "$UI_COLS" ]  || UI_COLS=80

    # The box needs a sane width; below this the layout cannot hold together.
    # 60 is not arbitrary: the settings put their values at interior column 17,
    # and the centred Start button lands at (BOX_W - 2 - 24) / 2 - which is also
    # 17 at this width, so the button lines up with the value column. The widest
    # row (the web-server chips) needs 57 of the 58 interior columns.
    BOX_W=60
    [ "$UI_COLS" -lt 64 ] && BOX_W=$(( UI_COLS - 4 ))
    [ "$BOX_W" -lt 40 ] && return 1

    # Cursor addressing is the one capability the differential redraw cannot do
    # without. If terminfo has no `cup` (TERM unset, TERM=dumb) there is no
    # point pretending - the caller falls back to non-interactive defaults.
    tput cup 0 0 >/dev/null 2>&1 || return 1

    ui_cup_cache
    ui_layout
    return 0
}

# Cursor-address strings for every line, resolved once. Doing this up front
# keeps tput out of the redraw path entirely.
ui_cup_cache() {
    local n max=$UI_LINES
    [ "$max" -gt 60 ] && max=60
    UI_CUP=()
    for (( n = 0; n < max; n++ )); do
        UI_CUP[$n]=$(tput cup "$n" 0 2>/dev/null)
    done
}

# put <line> <text> - write one line in place and clear whatever it overwrote.
put() { printf '%s%s%s' "${UI_CUP[$1]}" "$2" "$T_EL"; }

# There is deliberately no "measure the printable width of a styled string"
# helper. Stripping colour escapes with ${s//$'\033'\[*([0-9;])m/} is ~490x
# slower on a row carrying 16 escape sequences than on a plain one, and at six
# rows a frame that alone made the form take 100+ ms to repaint. Every builder
# below therefore produces the styled string *and* its plain-text twin, and the
# width is just ${#plain}.

# _pad <n> -> R_PAD. n spaces, via a builtin rather than a loop or a subshell.
_pad() {
    if [ "$1" -le 0 ]; then R_PAD=''; else printf -v R_PAD '%*s' "$1" ''; fi
}

# _line <n> <char> -> R_LINE. A run of n copies of a single character.
_line() {
    if [ "$1" -le 0 ]; then R_LINE=''; return; fi
    printf -v R_LINE '%*s' "$1" ''
    R_LINE=${R_LINE// /$2}
}

screen_setup() {
    ui_charset
    ui_glyphs
    ui_caps || return 1
    exec 4>&1        # keep the real stdout
    exec > "$TTY"    # the form draws on the terminal
    UI_READY=1
    # No clear here: the first draw_form is dirty and clears once itself.
    printf '%s%s' "$T_SMCUP" "$T_CIVIS"
    stty -echo < "$TTY" 2>/dev/null
    trap screen_restore EXIT INT TERM
    trap ui_repaint WINCH
    return 0
}

screen_restore() {
    [ "$UI_READY" -eq 1 ] || return 0
    UI_READY=0
    trap - WINCH
    stty echo < "$TTY" 2>/dev/null
    printf '%s%s' "$T_CNORM" "$T_RMCUP"
    exec >&4 4>&-
    trap - EXIT INT TERM
}

# A resize invalidates everything we think is on screen.
ui_repaint() {
    UI_LINES=$(cap lines); [ -n "$UI_LINES" ] || UI_LINES=24
    UI_COLS=$(cap cols);   [ -n "$UI_COLS" ]  || UI_COLS=80
    # 60 is not arbitrary: the settings put their values at interior column 17,
    # and the centred Start button lands at (BOX_W - 2 - 24) / 2 - which is also
    # 17 at this width, so the button lines up with the value column. The widest
    # row (the web-server chips) needs 57 of the 58 interior columns.
    BOX_W=60
    [ "$UI_COLS" -lt 64 ] && BOX_W=$(( UI_COLS - 4 ))
    ui_cup_cache
    ui_layout
    UI_DIRTY=1
}

# _read_key -> KEY. A symbolic name for one keypress: up down left right enter
# backspace escape, or the literal character. Assigns rather than echoes, so the
# key loop costs no fork at all.
_read_key() {
    local k rest
    IFS= read -rsn1 k < "$TTY" || return 1

    if [ "$k" == $'\033' ]; then
        # Arrow keys arrive as ESC [ A. Read the rest of the sequence, but do
        # not block: a bare ESC has nothing following it.
        IFS= read -rsn2 -t 0.05 rest < "$TTY"
        case $rest in
            '[A') KEY=up ;;
            '[B') KEY=down ;;
            '[C') KEY=right ;;
            '[D') KEY=left ;;
            '')   KEY=escape ;;
            *)    KEY=unknown ;;
        esac
        return 0
    fi

    case $k in
        '')            KEY=enter ;;      # read -n1 returns empty for newline
        $'\177'|$'\b') KEY=backspace ;;
        $'\t')         KEY=tab ;;
        *)             KEY=$k ;;
    esac
    return 0
}

#=[ box drawing ]======================================================================================================
#
# Rows are built as "content plus its printable width", then padded to the inside
# of the box. The focused row is filled edge to edge with a dark grey background,
# the way htop and lazygit mark a selection - so the highlight has to be applied
# to the padding too, not just the text.

# _box_top [title] -> R_BOX
# _box_top [styled-title] [plain-title] -> R_BOX
_box_top() {
    if [ -z "$1" ]; then
        _line $(( BOX_W - 2 )) "$G_H"
        R_BOX="${T_DIM}${G_TL}${R_LINE}${G_TR}${T_RESET}"
        return
    fi
    _line $(( BOX_W - ${#2} - 5 )) "$G_H"
    R_BOX="${T_DIM}${G_TL}${G_H} $1 ${R_LINE}${G_TR}${T_RESET}"
}

_box_sep() { _line $(( BOX_W - 2 )) "$G_H"; R_BOX="${T_DIM}${G_ML}${R_LINE}${G_MR}${T_RESET}"; }
_box_bot() { _line $(( BOX_W - 2 )) "$G_H"; R_BOX="${T_DIM}${G_BL}${R_LINE}${G_BR}${T_RESET}"; }

# _box_row <styled> <plain> [selected] -> R_BOX
_box_row() {
    local content=$1 selected=${3:-0}
    _pad $(( BOX_W - 2 - ${#2} ))

    if [ "$selected" -eq 1 ]; then
        R_BOX="${T_DIM}${G_V}${T_RESET}${T_BG_SEL}${content}${R_PAD}${T_RESET}${T_DIM}${G_V}${T_RESET}"
    else
        R_BOX="${T_DIM}${G_V}${T_RESET}${content}${R_PAD}${T_DIM}${G_V}${T_RESET}"
    fi
}

# _segments <current> <focused> <options...> -> R_SEG
#
# All choices stay on screen with the active one boxed and highlighted, rather
# than cycling through a single hidden value: with five PHP versions the user
# can see what exists and how far away it is. Standard practice for small,
# fixed option sets - the same idea as a radio group.
_segments() {
    local current=$1 focused=$2; shift 2
    local v out='' plain='' first=1 base=''
    [ "$focused" -eq 1 ] && base="${T_BG_SEL}${T_FG_SEL}"

    for v in "$@"; do
        if [ "$first" -eq 1 ]; then first=0; else out="${out} "; plain="${plain} "; fi
        plain="${plain}[ ${v} ]"
        if [ "$v" == "$current" ]; then
            if [ "$focused" -eq 1 ]; then
                out="${out}${T_RESET}${T_SEL_ON}${T_BOLD}[ ${v} ]${T_RESET}${base}"
            else
                out="${out}${T_BRACKET}[${T_RESET}${T_VAL}${T_BOLD} ${v} ${T_RESET}${T_BRACKET}]${T_RESET}"
            fi
        else
            if [ "$focused" -eq 1 ]; then
                out="${out}${T_BRACKET_SEL}[${T_RESET}${base}${T_VAL_OFF} ${v} ${T_RESET}${base}${T_BRACKET_SEL}]${T_RESET}${base}"
            else
                out="${out}${T_BRACKET_OFF}[${T_RESET}${T_VAL_OFF} ${v} ${T_RESET}${T_BRACKET_OFF}]${T_RESET}"
            fi
        fi
    done
    R_SEG=$out
    R_SEG_PLAIN=$plain
}

#=[ interactive form ]=================================================================================================

ROW_TEMPLATE=0
ROW_PHP=1
ROW_EMAIL=2
ROW_SSH=3
ROW_TZ=4
ROW_START=5
ROW_MAX=5

cursor=0

# Screen lines the box occupies. The wordmark sits above it when there is room;
# on a short terminal the layout silently loses the logo rather than the form.
ui_layout() {
    ART_H=0
    if [ "$UI_LINES" -ge 23 ] && [ "$BOX_W" -ge 45 ]; then
        ART_H=$(( ART_ROWS + 1 ))    # wordmark plus a blank line
    fi
    L_TOP=$(( 1 + ART_H ))
    L_OS=$(( L_TOP + 1 ))
    L_FACTS=$(( L_TOP + 2 ))
    L_SEP1=$(( L_TOP + 3 ))
    L_FIRST=$(( L_TOP + 4 ))     # settings run L_FIRST .. L_FIRST+4
    L_SEP2=$(( L_FIRST + 5 ))
    L_START=$(( L_SEP2 + 1 ))
    L_BOT=$(( L_START + 1 ))
    L_HELP=$(( L_BOT + 1 ))      # flush under the box, no blank line
    L_ERROR=$(( L_BOT + 2 ))
}

# Previously rendered text per line, so a keypress only rewrites what moved.
declare -a UI_SHOWN=()
UI_DIRTY=1

# _template_label <value> -> R_TL
_template_label() {
    local i
    for i in "${!TEMPLATES[@]}"; do
        if [ "${TEMPLATES[$i]}" == "$1" ]; then R_TL=${TEMPLATE_LABELS[$i]}; return; fi
    done
    R_TL=$1
}

# _cycle <list-name> <current> <delta> -> R_CYC
_cycle() {
    local -n list=$1
    local cur=$2 delta=$3 i n
    for i in "${!list[@]}"; do
        if [ "${list[$i]}" == "$cur" ]; then
            n=$(( (i + delta + ${#list[@]}) % ${#list[@]} ))
            R_CYC=${list[$n]}
            return
        fi
    done
    R_CYC=${list[0]}
}

# _index_of <needle> <haystack...> -> R_IDX
_index_of() {
    local needle=$1; shift
    local i=0
    for item in "$@"; do
        if [ "$item" == "$needle" ]; then R_IDX=$i; return; fi
        i=$(( i + 1 ))
    done
    R_IDX=0
}

# _row_text <row> -> R_ROW. The rendered inside of one setting row.
_row_text() {
    local row=$1 sel=0 label body plain base='' marker email
    [ "$cursor" -eq "$row" ] && sel=1
    [ "$sel" -eq 1 ] && base="${T_BG_SEL}${T_FG_SEL}"

    case $row in
        "$ROW_TEMPLATE")
            label='Web server'
            _template_label "$TEMPLATE"
            _segments "$R_TL" "$sel" "${TEMPLATE_LABELS[@]}"
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        "$ROW_PHP")
            label='PHP version'
            _segments "$PHP_VERSION" "$sel" "${PHP_VERSIONS[@]}"
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        "$ROW_EMAIL")
            label='Email stack'
            email='no'; [ "$WITH_EMAIL" -eq 1 ] && email='yes'
            _segments "$email" "$sel" no yes
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        "$ROW_SSH")
            label='SSH port'
            body="${T_WHITE}${T_BOLD}${SSH_PORT}${T_RESET}${base}"; plain=$SSH_PORT
            if [ "$sel" -eq 1 ]; then
                body="${body}   ${T_DIM}${G_ENTER} to edit${T_RESET}${base}"
                plain="${plain}   ${G_ENTER} to edit"
            fi ;;
        "$ROW_TZ")
            label='Timezone'
            body="${T_WHITE}${T_BOLD}${TIMEZONE}${T_RESET}${base}"; plain=$TIMEZONE
            if [ "$sel" -eq 1 ]; then
                body="${body}   ${T_DIM}${G_ENTER} to search${T_RESET}${base}"
                plain="${plain}   ${G_ENTER} to search"
            fi ;;
    esac

    if [ "$sel" -eq 1 ]; then
        marker="${T_YELLOW}${G_ARROW}${T_RESET}${base}"
    else
        marker=' '
    fi
    printf -v R_ROW       '%s %s %-13s %s' "$base" "$marker" "$label" "$body"
    printf -v R_ROW_PLAIN  ' %s %-13s %s'   "${marker:+ }" "$label" "$plain"
}

# _start_text -> R_ROW
_start_text() {
    # A button, not a line of text - it should read as the thing you press even
    # when the cursor is elsewhere. Focus brightens it and adds the marker.
    local inner pad
    if [ "$cursor" -eq "$ROW_START" ]; then
        inner=" ${G_ARROW} Start installation ${G_ARROW2} "
    else
        inner="   Start installation   "
    fi

    # Centred in the box, and the same width in both states so the button does
    # not jump sideways when the cursor lands on it. Derived from BOX_W rather
    # than hardcoded, so it stays centred on a narrow terminal too.
    _pad $(( (BOX_W - 2 - ${#inner}) / 2 ))
    pad=$R_PAD

    if [ "$cursor" -eq "$ROW_START" ]; then
        R_ROW="${pad}${T_BTN_SEL}${inner}${T_RESET}"
    else
        R_ROW="${pad}${T_BTN}${inner}${T_RESET}"
    fi
    R_ROW_PLAIN="${pad}${inner}"
}

# paint <line> <text> - write the line only when it differs from what is there.
paint() {
    if [ "$UI_DIRTY" -eq 1 ] || [ "${UI_SHOWN[$1]}" != "$2" ]; then
        put "$1" "$2"
        UI_SHOWN[$1]=$2
    fi
}

draw_form() {
    local i s facts

    if [ "$UI_DIRTY" -eq 1 ]; then
        printf '%s' "$T_CLEAR"
        UI_SHOWN=()

        if [ "$ART_H" -gt 0 ]; then
            # Blue gradient down the wordmark, matching the logo.
            local -a shades=("$T_LOGO1" "$T_LOGO2" "$T_LOGO2" "$T_LOGO3" "$T_LOGO4" "$T_LOGO4")
            local tag=''
            # The version rides on the baseline row, to the right of the mark.
            [ -n "$REQAD_VERSION" ] && tag="    ${T_AMBER}v${REQAD_VERSION}${T_RESET}"
            for (( i = 0; i < ART_ROWS; i++ )); do
                if [ "$i" -eq 4 ]; then
                    paint $(( i + 1 )) "  ${T_BOLD}${shades[$i]}${ART[$i]}${T_RESET}${tag}"
                else
                    paint $(( i + 1 )) "  ${T_BOLD}${shades[$i]}${ART[$i]}${T_RESET}"
                fi
            done
            paint $ART_H ''
        fi

        facts="$FACT_HOST"
        [ -n "$FACT_IP" ]     && facts="$facts   $FACT_IP"
        [ -n "$FACT_VCORES" ] && facts="$facts   ${FACT_VCORES} vCPU"
        [ -n "$FACT_MEM" ]    && facts="$facts   ${FACT_MEM} RAM"

        _box_top '';                                    paint $L_TOP   "$R_BOX"
        _box_row " ${T_GREY}${OS_PRETTY} ${G_DOT} ${ARCH}${T_RESET}" " ${OS_PRETTY} ${G_DOT} ${ARCH}"
        paint $L_OS "$R_BOX"
        _box_row " ${T_DIM}${facts}${T_RESET}" " ${facts}"
        paint $L_FACTS "$R_BOX"
        _box_sep;                                       paint $L_SEP1  "$R_BOX"
        _box_sep;                                       paint $L_SEP2  "$R_BOX"
        _box_bot;                                       paint $L_BOT   "$R_BOX"
        paint $L_HELP "  ${T_HELP}${G_UD} move ${G_DOT} ${G_LR} change ${G_DOT} ${G_ENTER} select ${G_DOT} q quit${T_RESET}"
    fi

    for (( i = 0; i <= ROW_MAX - 1; i++ )); do
        s=0; [ "$cursor" -eq "$i" ] && s=1
        _row_text "$i"
        _box_row "$R_ROW" "$R_ROW_PLAIN" "$s"
        paint $(( L_FIRST + i )) "$R_BOX"
    done

    _start_text
    _box_row "$R_ROW" "$R_ROW_PLAIN" 0
    paint $L_START "$R_BOX"

    if [ -n "$FORM_ERROR" ]; then
        paint $L_ERROR "  ${T_RED}${G_CROSS} ${FORM_ERROR}${T_RESET}"
    else
        paint $L_ERROR ''
    fi

    UI_DIRTY=0
}

# edit_port replaces the SSH port with a small line editor, drawn over the form.
edit_port() {
    local buf="$SSH_PORT" err='' prev_buf='' prev_err='x'

    printf '%s' "$T_CLEAR"
    _box_top "${T_YELLOW}${T_BOLD}SSH port${T_RESET}${T_DIM}" 'SSH port'; put 1 "$R_BOX"
    _box_row '' '';                                                       put 2 "$R_BOX"
    _box_row '' '';                                                       put 4 "$R_BOX"
    _box_bot;                                                 put 5 "$R_BOX"
    put 7 "  ${T_DIM}type a number ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"

    while :; do
        if [ "$buf" != "$prev_buf" ]; then
            _box_row "  Port: ${T_WHITE}${T_BOLD}${buf}${T_RESET}${T_YELLOW}█${T_RESET}" "  Port: ${buf}█"
            put 3 "$R_BOX"
            prev_buf=$buf
        fi
        if [ "$err" != "$prev_err" ]; then
            if [ -n "$err" ]; then
                put 6 "  ${T_RED}${G_CROSS} ${err}${T_RESET}"
            else
                put 6 ''
            fi
            prev_err=$err
        fi

        _read_key || { UI_DIRTY=1; return 1; }
        case $KEY in
            escape) UI_DIRTY=1; return 1 ;;
            enter)
                if valid_port "$buf"; then
                    SSH_PORT=$buf
                    UI_DIRTY=1
                    return 0
                fi
                err="'${buf}' is not a port between 1 and 65535" ;;
            backspace) buf=${buf%?}; err='' ;;
            [0-9])     buf="${buf}${KEY}"; err='' ;;
        esac
    done
}

# edit_timezone: type to filter, arrows to choose, enter to accept.
#
# The zone list is read once into an array and filtered in-process on every
# keystroke. Only the list region is repainted, and only when the filter or the
# selection actually moved.
edit_timezone() {
    local -a zones matches
    local filter='' i start shown sel=0
    local prev_filter='x' prev_sel=-1 prev_start=-1 prev_count=-1

    mapfile -t zones < <(list_timezones)
    [ ${#zones[@]} -gt 0 ] || { FORM_ERROR='no timezone database found'; return 1; }

    # Rank matches so the obvious answer comes first: typing "tok" must offer
    # Asia/Tokyo before Antarctica/Vostok, and "buch" must offer Bucharest.
    filter_matches() {
        matches=()
        local z city f=${filter,,}
        local -a tier1=() tier2=() tier3=()

        if [ -z "$f" ]; then
            matches=("${zones[@]}")
            _index_of "$TIMEZONE" "${matches[@]}"
            sel=$R_IDX
            return
        fi

        # Underscores and spaces are ignored on both sides, so "new york",
        # "newyork" and "new_york" all find America/New_York.
        f=${f//_/}; f=${f// /}

        local zn cityn
        for z in "${zones[@]}"; do
            city=${z##*/}
            zn=${z,,};       zn=${zn//_/}
            cityn=${city,,}; cityn=${cityn//_/}
            if [[ $cityn == "$f"* ]]; then
                tier1+=("$z")            # city starts with the filter
            elif [[ $zn == "$f"* ]]; then
                tier2+=("$z")            # region starts with it, e.g. "europe/b"
            elif [[ $zn == *"$f"* ]]; then
                tier3+=("$z")            # matches somewhere
            fi
        done

        matches=("${tier1[@]}" "${tier2[@]}" "${tier3[@]}")
        sel=0
    }
    filter_matches

    shown=10
    printf '%s' "$T_CLEAR"
    _box_top "${T_YELLOW}${T_BOLD}Timezone${T_RESET}${T_DIM}" 'Timezone'; put 1 "$R_BOX"
    _box_sep;                                                  put 3 "$R_BOX"
    _box_bot;                                                  put $(( 4 + shown + 1 )) "$R_BOX"
    put $(( 4 + shown + 3 )) "  ${T_DIM}type to filter ${G_DOT} ${G_UD} choose ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"

    while :; do
        if [ "$filter" != "$prev_filter" ]; then
            _box_row "  ${T_GREY}Search:${T_RESET} ${T_WHITE}${T_BOLD}${filter}${T_RESET}${T_YELLOW}█${T_RESET}" "  Search: ${filter}█"
            put 2 "$R_BOX"
            prev_filter=$filter
        fi

        # Keep the selected entry on screen when the list is longer than the box.
        start=0
        [ $sel -ge $shown ] && start=$(( sel - shown + 1 ))

        if [ $sel -ne $prev_sel ] || [ $start -ne $prev_start ] || [ ${#matches[@]} -ne $prev_count ]; then
            if [ ${#matches[@]} -eq 0 ]; then
                _box_row "  ${T_RED}no timezone matches '${filter}'${T_RESET}" "  no timezone matches '${filter}'"
                put 4 "$R_BOX"
                # Clear through the "... N more" line too, or it survives as a
                # stale count under the "no matches" message.
                _box_row '' ''
                for (( i = 1; i <= shown; i++ )); do put $(( 4 + i )) "$R_BOX"; done
            else
                for (( i = 0; i < shown; i++ )); do
                    if [ $(( start + i )) -lt ${#matches[@]} ]; then
                        if [ $(( start + i )) -eq $sel ]; then
                            _box_row "${T_BG_SEL}${T_FG_SEL}  ${T_GREEN}${G_ARROW}${T_RESET}${T_BG_SEL}${T_FG_SEL} ${T_BOLD}${matches[$(( start + i ))]}${T_RESET}" "  ${G_ARROW} ${matches[$(( start + i ))]}" 1
                        else
                            _box_row "    ${T_GREY}${matches[$(( start + i ))]}${T_RESET}" "    ${matches[$(( start + i ))]}"
                        fi
                    else
                        _box_row '' ''
                    fi
                    put $(( 4 + i )) "$R_BOX"
                done
                if [ ${#matches[@]} -gt $(( start + shown )) ]; then
                    _box_row "    ${T_DIM}${G_DOT}${G_DOT}${G_DOT} $(( ${#matches[@]} - start - shown )) more${T_RESET}" "    ${G_DOT}${G_DOT}${G_DOT} $(( ${#matches[@]} - start - shown )) more"
                else
                    _box_row '' ''
                fi
                put $(( 4 + shown )) "$R_BOX"
            fi
            prev_sel=$sel; prev_start=$start; prev_count=${#matches[@]}
        fi

        _read_key || { UI_DIRTY=1; return 1; }
        case $KEY in
            escape) UI_DIRTY=1; return 1 ;;
            up)     [ $sel -gt 0 ] && sel=$(( sel - 1 )) ;;
            down)   [ $sel -lt $(( ${#matches[@]} - 1 )) ] && sel=$(( sel + 1 )) ;;
            enter)
                if [ ${#matches[@]} -gt 0 ]; then
                    TIMEZONE=${matches[$sel]}
                    UI_DIRTY=1
                    return 0
                fi ;;
            backspace)
                filter=${filter%?}
                filter_matches ;;
            left|right|tab|unknown) ;;
            *)
                if [ ${#KEY} -eq 1 ]; then
                    filter="${filter}${KEY}"
                    filter_matches
                fi ;;
        esac
    done
}

list_timezones() {
    if command -v timedatectl >/dev/null 2>&1; then
        timedatectl list-timezones 2>/dev/null && return 0
    fi
    # Fall back to the zoneinfo tree; good enough and always present.
    if [ -d /usr/share/zoneinfo ]; then
        find /usr/share/zoneinfo -type f -printf '%P\n' 2>/dev/null \
            | grep -E '^[A-Z][A-Za-z_]+/' | sort
    fi
}

run_form() {
    has_tty || return 1
    screen_setup || return 1

    FORM_ERROR=''
    UI_DIRTY=1

    while :; do
        draw_form
        _read_key || { screen_restore; return 1; }
        FORM_ERROR=''

        case $KEY in
            q|Q) screen_restore; return 1 ;;

            up|k)       [ $cursor -gt 0 ] && cursor=$(( cursor - 1 )) ;;
            down|j|tab) [ $cursor -lt $ROW_MAX ] && cursor=$(( cursor + 1 )) ;;

            left|h)  form_cycle -1 ;;
            right|l) form_cycle 1 ;;
            ' ')     form_cycle 1 ;;

            enter)
                case $cursor in
                    "$ROW_SSH")   edit_port ;;
                    "$ROW_TZ")    edit_timezone ;;
                    "$ROW_START") screen_restore; return 0 ;;
                    *)            form_cycle 1 ;;
                esac ;;
        esac
    done
}

form_cycle() {
    case $cursor in
        "$ROW_TEMPLATE") _cycle TEMPLATES "$TEMPLATE" "$1";       TEMPLATE=$R_CYC ;;
        "$ROW_PHP")      _cycle PHP_VERSIONS "$PHP_VERSION" "$1"; PHP_VERSION=$R_CYC ;;
        "$ROW_EMAIL")    WITH_EMAIL=$(( 1 - WITH_EMAIL )) ;;
    esac
}

#=[ handover ]=========================================================================================================

fetch_backend() {
    local src="${REPO}/${BACKEND}" dst="${WORKDIR}/${BACKEND}"

    if [ -n "$SCRIPT_OVERRIDE" ]; then
        case $SCRIPT_OVERRIDE in
            http://*|https://*) src=$SCRIPT_OVERRIDE ;;
            *)
                [ -f "$SCRIPT_OVERRIDE" ] || die "--script ${SCRIPT_OVERRIDE}: no such file"
                # Absolute, because run_backend cds into the working directory.
                readlink -f "$SCRIPT_OVERRIDE"
                return 0 ;;
        esac
    fi

    mkdir -p "$WORKDIR" || die "cannot create ${WORKDIR}"

    if command -v curl >/dev/null 2>&1; then
        curl -fsSL -o "$dst" "$src" || die "could not download ${src}"
    else
        wget -q -O "$dst" "$src" || die "could not download ${src}"
    fi

    [ -s "$dst" ] || die "downloaded an empty ${BACKEND}"
    chmod 0700 "$dst"
    echo "$dst"
}

run_backend() {
    local args=()
    [ "$SKIP_UPDATE" -eq 1 ] && args+=(--skip-update)

    if [ "$DRY_RUN" -eq 1 ]; then
        printf "\n  ${BOLD}Dry run - nothing will be changed.${NC}\n\n"
        printf "  detected   %s · %s\n" "$OS_PRETTY" "$ARCH"
        printf "  backend    %s\n\n" "$BACKEND"
        printf "  TEMPLATE=%s\n"     "$TEMPLATE"
        printf "  PHP_VERSION=%s\n"  "$PHP_VERSION"
        printf "  WITH_EMAIL=%s\n"   "$WITH_EMAIL"
        printf "  SSH_PORT=%s\n"     "$SSH_PORT"
        printf "  TIMEZONE=%s\n\n"   "$TIMEZONE"
        printf "  would run  bash %s %s\n\n" "$BACKEND" "${args[*]}"
        return 0
    fi

    local script
    script=$(fetch_backend) || exit 1

    local email_label='no'
    [ "$WITH_EMAIL" -eq 1 ] && email_label='yes'

    printf "\n  ${GREEN}▶${NC} %s · %s · php %s · email %s · ssh %s · %s\n" \
        "$OS_PRETTY" "$TEMPLATE" "$PHP_VERSION" "$email_label" "$SSH_PORT" "$TIMEZONE"
    printf "  ${GREY}log: %s/install_reqad.log${NC}\n\n" "$WORKDIR"

    cd "$WORKDIR" || die "cannot enter ${WORKDIR}"

    TEMPLATE="$TEMPLATE" \
    PHP_VERSION="$PHP_VERSION" \
    WITH_EMAIL="$WITH_EMAIL" \
    SSH_PORT="$SSH_PORT" \
    TIMEZONE="$TIMEZONE" \
        bash "$script" "${args[@]}"
}

#=[ main ]=============================================================================================================

main() {
    parse_args "$@"

    [ -n "$TIMEZONE" ] || TIMEZONE=$(current_timezone)

    detect_platform
    gather_facts

    if [ "$(id -u)" -ne 0 ] && [ "$DRY_RUN" -eq 0 ]; then
        die "the Reqad installer must run as root"
    fi

    if [ -z "$BACKEND" ]; then
        die "${OS_PRETTY}: ${UNSUPPORTED}"
    fi

    # A local --script needs nothing from the repository.
    case $SCRIPT_OVERRIDE in
        ''|http://*|https://*)
            [ "$DRY_RUN" -eq 1 ] || check_repo ;;
    esac

    # No terminal (piped, cron, packer) means take the defaults rather than hang.
    if [ "$INTERACTIVE" -eq 1 ] && ! has_tty; then
        INTERACTIVE=0
    fi

    if [ "$INTERACTIVE" -eq 1 ]; then
        reqad_version
        if ! run_form; then
            printf "\nCancelled, nothing was changed.\n"
            exit 0
        fi
    fi

    run_backend
}

main "$@"
