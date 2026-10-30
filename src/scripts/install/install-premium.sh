#!/bin/bash
#
# Reqad Premium installer.
#
#   curl -sSL https://repo.reqad.net/install-premium.sh | bash
#   curl -sSL https://repo.reqad.net/install-premium.sh | bash -s -- --template nginx_apache_php-fpm --php-extra 7.4,8.4
#
# Installs the base system with the free installer (install.sh -> install-el.sh,
# run non-interactively with the options below), then turns on the premium
# repository and adds the premium parts: the add-ons (rpms from reqad-premium),
# more PHP versions and the feature switches in etc/server-software.ini.
#
# Run with no options for the form (the same one as install.sh, drawn with
# terminfo); any option runs it non-interactively, with defaults for the rest.
# Every add-on is installed unless left out with --no-<add-on>, except WireGuard
# and Minecraft, which are installed only when asked for. Run it again on a
# server that already runs Reqad (the form sees that; on the command line pass
# --addons-only) to add what is missing: every step skips what is already there.

VERSION='0.2.1 - Oct 30, 2026'

REPO="${REQAD_REPO:-https://repo.reqad.net}"
REPO="${REPO%/}"

# Where the base installer and this one write their logs (and csf is unpacked).
WORKDIR='/var/tmp/reqad-install'
LOG="${WORKDIR}/install_premium.log"

RPATH='/usr/local/reqad'
INI="${RPATH}/etc/server-software.ini"
# The panel's own PHP, which has app/functions/ loaded in the panel
RPHP="/opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini"

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[0;33m'
GREY='\033[0;90m'
BOLD='\033[1m'
NC='\033[0m'

TEMPLATES=(nginx_php-fpm apache_modphp nginx_apache_php-fpm)
TEMPLATE_LABELS=('nginx + fpm' 'apache + mod_php' 'nginx + apache')
PHP_VERSIONS=(7.4 8.2 8.3 8.4 8.5)
# What PHP Settings > Install PHP offers (php_installable_versions() in
# app/functions/php.php; 5.4 is an EL8 build of the premium repository)
PHP_EXTRA_VERSIONS=(5.6 7.2 7.3 7.4 8.0 8.1 8.2 8.3 8.4 8.5)
CLAMAV_MODES=(none tag deny)

# Base system
TEMPLATE='nginx_php-fpm'
TEMPLATE_GIVEN=0
PHP_VERSION='8.3'
PHP_EXTRA=''
WITH_EMAIL=1
SSH_PORT='22'
TIMEZONE=''
ADMIN_EMAIL=''
SSH_KEY_IN=''
SKIP_UPDATE=0
ADDONS_ONLY=0
BASE_SCRIPT=''

# Add-ons: 1 = install
A_ACCOUNT=1
A_HOSTING=1
A_MODSEC=1
A_FTP=1
A_CLAMAV=1
A_WIREGUARD=0
A_MINECRAFT=0
CLAMAV_SCAN='none'

# server-software.ini switches (they make a feature exist; each is still
# turned on in the panel: Settings > API access, AI access, ...)
F_API=1
F_MCP=1
F_DEPLOY=1
F_DNSCLUSTER=0

# Remi's PHP packages: 0 = Remi's public mirrors, 1 = our copy on repo.reqad.net
REMI_LOCAL=0
REMI_LOCAL_URL="${REPO}/remi/enterprise"

USE_TEST=0
DRY_RUN=0
INTERACTIVE=1

die() {
    printf "\n ${RED}ERROR:${NC} %b\n\n" "$1" >&2
    exit 1
}

usage() {
    cat <<EOF
Reqad Premium installer ${VERSION}

Usage:
  install-premium.sh [options]

Run with no options for the interactive installer. Passing any option below
(except --test, --base-script and --dry-run) runs non-interactively, with the
defaults for anything not specified.

Base system:
  --template NAME      web server stack                              (default nginx_php-fpm)
                         nginx_php-fpm         nginx + PHP-FPM
                         apache_modphp         Apache + mod_php
                         nginx_apache_php-fpm  Apache behind nginx (needs Hosting Pro)
  --php VERSION        default PHP: ${PHP_VERSIONS[*]}                  (default 8.3)
  --php-extra LIST     more PHP versions next to it, e.g. 7.4,8.4
                       (${PHP_EXTRA_VERSIONS[*]}; 5.4 on EL8)
  --remi-local         take Remi's PHP packages from the copy on repo.reqad.net/remi/
                       instead of Remi's public mirrors                (default: mirrors)
  --no-email           no mail server (exim/dovecot); ClamAV is then left out too
  --ssh-port PORT      SSH port                                      (default 22)
  --ssh-key 'KEY'      root's SSH public key, when root has none yet
  --timezone TZ        e.g. Europe/Bucharest                         (default: keep current)
  --admin-email ADDR   address for the Let's Encrypt account
  --skip-update        skip the initial dnf update and package install
  --addons-only        Reqad is installed already: only the premium parts

Add-ons (installed unless left out):
  --no-account-panel   the account panel on port 2083            (reqad-account)
  --no-hosting-pro     hosting packages, quotas, extra IPs       (reqad-hosting-pro)
  --no-modsecurity     web application firewall, nginx only      (reqad-modsecurity)
  --no-pureftpd        FTP accounts with Pure-FTPd               (reqad-pureftpd)
  --no-clamav          virus scanning of mail                    (reqad-clamav)
  --clamav-scan MODE   how exim uses it: tag | deny | none       (default none: set it on the Email page)

Add-ons (installed only when asked for):
  --wireguard          WireGuard VPN peers                       (reqad-wireguard)
  --minecraft          a Minecraft Java server                   (reqad-minecraft)

Features (switched on unless left out; each is then turned on in Settings):
  --no-api             REST API
  --no-mcp             AI assistants (MCP)
  --no-deployments     Git deployments and Node.js / Python apps
  --dns-cluster        this server is the primary of a DNS cluster: installs
                       PowerDNS and the Cluster / API keys tabs (off by default)

Other:
  -y, --yes            non-interactive, accept all defaults
  --test               install the add-ons from the test repository (maintainers)
  --base-script PATH   use this install.sh instead of the repository's (testing)
  --dry-run            print what would be done, change nothing
  -h, --help           this help
  -V, --version        print version

The server's IP address must be allowed on the premium repository first.

Examples:
  install-premium.sh
  install-premium.sh --template nginx_apache_php-fpm --php 8.3 --php-extra 7.4,8.4 --timezone Europe/Bucharest
  install-premium.sh --addons-only --minecraft
EOF
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

valid_ssh_key() {
    [[ $1 =~ ^(ssh-[a-z0-9-]+|ecdsa-sha2-nistp[0-9]+|sk-[a-z0-9@.-]+)\ [A-Za-z0-9+/]+=*(\ .*)?$ ]]
}

valid_email() {
    [[ $1 =~ ^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$ ]]
}

#=[ arguments ]========================================================================================================

parse_args() {
    local saw_config=0
    while [ $# -gt 0 ]; do
        case $1 in
            --*=*) set -- "${1%%=*}" "${1#*=}" "${@:2}" ;;
        esac
        case $1 in
            -h|--help)    usage; exit 0 ;;
            -V|--version) echo "Reqad Premium installer ${VERSION}"; exit 0 ;;

            --template)
                [ $# -ge 2 ] || die "--template needs a value"
                in_list "$2" "${TEMPLATES[@]}" || die "invalid --template '$2'\n        valid: ${TEMPLATES[*]}"
                TEMPLATE=$2; TEMPLATE_GIVEN=1; shift 2 ;;
            --php)
                [ $# -ge 2 ] || die "--php needs a value"
                in_list "$2" "${PHP_VERSIONS[@]}" || die "invalid --php '$2'\n        valid: ${PHP_VERSIONS[*]}"
                PHP_VERSION=$2; shift 2 ;;
            --php-extra)
                [ $# -ge 2 ] || die "--php-extra needs a list, e.g. 7.4,8.4"
                PHP_EXTRA=$(echo "$2" | tr ', ' '\n\n' | grep -v '^$' | sort -uV | paste -sd' ' -); shift 2 ;;
            --email)       WITH_EMAIL=1; shift ;;
            --no-email)    WITH_EMAIL=0; shift ;;
            --ssh-port)
                [ $# -ge 2 ] || die "--ssh-port needs a value"
                valid_port "$2" || die "invalid --ssh-port '$2' (1-65535)"
                SSH_PORT=$2; shift 2 ;;
            --ssh-key)
                [ $# -ge 2 ] || die "--ssh-key needs a value"
                valid_ssh_key "$2" || die "--ssh-key does not look like a public key (ssh-ed25519 AAAA...)"
                SSH_KEY_IN=$2; shift 2 ;;
            --timezone)
                [ $# -ge 2 ] || die "--timezone needs a value"
                [ -f "/usr/share/zoneinfo/$2" ] || die "unknown timezone '$2'"
                TIMEZONE=$2; shift 2 ;;
            --admin-email)
                [ $# -ge 2 ] || die "--admin-email needs a value"
                valid_email "$2" || die "invalid --admin-email '$2'"
                ADMIN_EMAIL=$2; shift 2 ;;
            --skip-update) SKIP_UPDATE=1; shift ;;
            --addons-only) ADDONS_ONLY=1; shift ;;

            --no-account-panel) A_ACCOUNT=0; shift ;;
            --no-hosting-pro)   A_HOSTING=0; shift ;;
            --no-modsecurity)   A_MODSEC=0; shift ;;
            --no-pureftpd)      A_FTP=0; shift ;;
            --no-clamav)        A_CLAMAV=0; shift ;;
            --clamav-scan)
                [ $# -ge 2 ] || die "--clamav-scan needs a value"
                in_list "$2" "${CLAMAV_MODES[@]}" || die "invalid --clamav-scan '$2' (tag, deny or none)"
                CLAMAV_SCAN=$2; shift 2 ;;
            --wireguard)        A_WIREGUARD=1; shift ;;
            --minecraft)        A_MINECRAFT=1; shift ;;

            --no-api)           F_API=0; shift ;;
            --no-mcp)           F_MCP=0; shift ;;
            --no-deployments)   F_DEPLOY=0; shift ;;
            --dns-cluster)      F_DNSCLUSTER=1; shift ;;
            --remi-local)       REMI_LOCAL=1; shift ;;

            -y|--yes)      shift ;;
            # These three work with the form too, so they do not count as options
            --test)        USE_TEST=1; shift; continue ;;
            --base-script)
                [ $# -ge 2 ] || die "--base-script needs a value"
                BASE_SCRIPT=$2; shift 2; continue ;;
            --dry-run)     DRY_RUN=1; shift; continue ;;

            *) die "unknown option '$1' (try --help)" ;;
        esac
        saw_config=1
    done
    [ "$saw_config" -eq 1 ] && INTERACTIVE=0

    if [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && [ "$A_HOSTING" -eq 0 ]; then
        die "--template nginx_apache_php-fpm needs Hosting Pro (leave out --no-hosting-pro)"
    fi
    local v
    for v in $PHP_EXTRA; do
        in_list "$v" "${PHP_EXTRA_VERSIONS[@]}" 5.4 \
            || die "invalid --php-extra version '$v'\n        valid: ${PHP_EXTRA_VERSIONS[*]} (5.4 on EL8)"
    done
    return 0
}

#=[ checks ]===========================================================================================================

detect_platform() {
    EL=$(rpm -E %rhel 2>/dev/null)
    ARCH=$(uname -m)
    [ "$ARCH" == "x86_64" ] || die "architecture ${ARCH} is not supported - Reqad requires x86_64"
    case $EL in
        8|9) ;;
        *)   die "only Enterprise Linux 8 and 9 (Rocky, AlmaLinux, RHEL, Oracle, CentOS Stream) are supported" ;;
    esac
    local v
    for v in $PHP_EXTRA; do
        [ "$v" != "5.4" ] || [ "$EL" -eq 8 ] || die "PHP 5.4 is built for EL8 only"
    done
    # In a subshell: /etc/os-release would overwrite this script's $VERSION
    OS_PRETTY=$( . /etc/os-release 2>/dev/null; echo "$PRETTY_NAME" )
    [ -n "$OS_PRETTY" ] || OS_PRETTY="Enterprise Linux ${EL}"
}

current_timezone() {
    local tz
    tz=$(timedatectl show --property=Timezone --value 2>/dev/null)
    if [ -z "$tz" ] && [ -L /etc/localtime ]; then
        tz=$(readlink /etc/localtime)
        tz=${tz#*/zoneinfo/}
    fi
    echo "${tz:-UTC}"
}

http_code() {
    if command -v curl >/dev/null 2>&1; then
        curl -s -o /dev/null -w '%{http_code}' --max-time 15 "$1" 2>/dev/null
    else
        wget -q -S --spider --timeout=15 "$1" 2>&1 | awk '/HTTP\//{c=$2} END{print c}'
    fi
}

# Before anything changes: the premium repository only answers allowed addresses.
check_premium_repo() {
    local tier code
    for tier in premium $([ "$USE_TEST" -eq 1 ] && echo test); do
        code=$(http_code "${REPO}/${tier}/el${EL}/RPMS/x86_64/repodata/repomd.xml")
        check_repo_code "$tier" "$code"
    done
}

check_repo_code() {
    local tier=$1 code=$2
    case $code in
        200) return 0 ;;
        401|403)
            die "the ${tier} repository refused this server (HTTP ${code}).
        Ask for this server's IP address to be allowed on it, then run the
        installer again." ;;
        000|'') die "cannot reach ${REPO} - check the network and DNS" ;;
        *)      die "${REPO} returned HTTP ${code} for the ${tier} repository" ;;
    esac
}

# The web server of a server that runs Reqad already can only go from nginx
# alone to Apache behind nginx (Hosting Pro's `reqad webserver switch`).
template_reachable() {
    local cur=$1 to=$2
    [ "$to" == "$cur" ] || { [ "$cur" == "nginx_php-fpm" ] && [ "$to" == "nginx_apache_php-fpm" ]; }
}

#=[ helpers ]==========================================================================================================

# "Label ............ [ STATE ]", in the base installer's style
say() {
    printf "%-36s" "$1"
    case $2 in
        DONE|OK)  echo -e "[ ${GREEN}$2${NC} ]${3:+ ${GREY}$3${NC}}" ;;
        SKIPPED)  echo -e "[ ${YELLOW}$2${NC} ]${3:+ ${GREY}$3${NC}}" ;;
        *)        echo -e "[ ${RED}$2${NC} ]${3:+ ${GREY}$3${NC}}" ;;
    esac
}

RESULTS=()
result() { RESULTS+=("$(printf '%-28s %s' "$1" "$2")"); }

reqad_running() {
    [ -x "${RPATH}/bin/reqad" ] && systemctl is-active --quiet reqad
}

ini_get() {
    grep -m1 "^$1=" "$INI" 2>/dev/null | cut -d= -f2- | tr -d '[:space:]'
}

# key=value in the [reqad] section, as the add-on rpms write their switches
ini_set() {
    if grep -q "^$1=" "$INI"; then
        sed -i "s/^$1=.*/$1=$2/" "$INI"
    else
        sed -i "/^\[reqad\]/a $1=$2" "$INI"
    fi
}

# Add a value to a comma list (php_versions, services), sorted, once
ini_list_add() {
    local cur new
    cur=$(grep -m1 "^$1=" "$INI" | cut -d= -f2-)
    new=$( { echo "$cur" | tr ',' '\n'; echo "$2"; } | sed 's/^[[:space:]]*//; s/[[:space:]]*$//' | grep -v '^$' | sort -uV | paste -sd, - | sed 's/,/, /g')
    sed -i "s|^$1=.*|$1=${new}|" "$INI"
}

DNF_REPOS=()

dnf_install() {
    dnf -y install "${DNF_REPOS[@]}" "$@" >> "$LOG" 2>&1
}

# An add-on rpm: one at a time, so a package that cannot be installed does not
# take the others down with it.
addon() {
    local label=$1 pkg=$2
    if rpm -q --quiet "$pkg"; then
        say "$label" OK "already installed"
        result "$label" "already installed"
        return 0
    fi
    if dnf_install "$pkg" && rpm -q --quiet "$pkg"; then
        say "$label" DONE
        result "$label" "installed ($(rpm -q --qf '%{VERSION}-%{RELEASE}' "$pkg"))"
        return 0
    fi
    say "$label" ERROR "see ${LOG}"
    result "$label" "FAILED (see ${LOG})"
    return 1
}

#=[ terminal helpers - terminfo / ncurses ]============================================================================
#
# The same form and the same helpers as install.sh (copied: each installer is
# fetched on its own). The screen is painted once, then only the lines that
# changed are rewritten. Two rules keep it fast on a slow or remote terminal:
# every terminfo capability is looked up once at startup, and nothing in the
# redraw path forks - helpers hand back values in globals (R_*) instead of
# echoing them into a command substitution.
#
# The premium form has more settings than a terminal has lines, so they scroll
# inside the box (a viewport over ITEMS); the Start button stays put under it.

shopt -s extglob

TTY='/dev/tty'

# Actually open the terminal rather than testing its permission bits: /dev/tty
# is world read/write even when the process has no controlling terminal.
has_tty() { ( exec 3<>"$TTY" ) 2>/dev/null; }

UI_READY=0

# cap <name> [args...] - a terminfo capability, or empty when unsupported.
cap() { tput "$@" 2>/dev/null || true; }

# Line-drawing characters only measure right under a UTF-8 locale.
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
        G_UP='↑'; G_DN='↓'; G_ELL='…'; G_CUR='█'
    else
        G_TL='+'; G_TR='+'; G_BL='+'; G_BR='+'
        G_H='-';  G_V='|';  G_ML='+'; G_MR='+'
        G_ARROW='>'; G_ARROW2='<'; G_DOT='-'; G_UD='up/dn'; G_LR='left/right'; G_ENTER='enter'; G_CROSS='x'
        G_UP='^'; G_DN='v'; G_ELL='~'; G_CUR='_'
    fi

    # Wordmark, figlet "slant", as install.sh
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

# A bare TERM=xterm is nearly always 256-colour capable; upgrade it when a
# matching terminfo entry exists (never TERM=linux, a real 8-colour console).
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
        *)  [ -n "$COLORTERM" ] && cand="${TERM}-256color" ;;
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

    if [ "$colors" -ge 256 ]; then
        T_BG_SEL=$(cap setab 233)
        T_GREY=$(cap setaf 244)
        T_DIM=$(cap setaf 240)
        T_FG_SEL=$(cap setaf 255)
        T_SEL_ON="$(cap setab 22)$(cap setaf 231)"
        T_BRACKET=$(cap setaf 237)
        T_BRACKET_OFF=$(cap setaf 233)
        T_BRACKET_SEL=$(cap setaf 239)
        T_HELP=$(cap setaf 237)
        T_VAL=$(cap setaf 252)
        T_VAL_OFF=$(cap setaf 242)
        T_BTN="$(cap setab 22)$(cap setaf 252)"
        T_BTN_SEL="$(cap setab 22)$(cap setaf 231)"
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

    ui_size
    # Below 60 columns or 16 lines the form cannot hold together
    [ "$BOX_W" -lt 60 ] && return 1

    # Cursor addressing is the one capability the redraw cannot do without
    tput cup 0 0 >/dev/null 2>&1 || return 1

    ui_cup_cache
    ui_layout
}

# 74 columns: the label column (17) plus the widest row, the web server chips
# (55). A narrower terminal gets shorter chip labels.
ui_size() {
    UI_LINES=$(cap lines); [ -n "$UI_LINES" ] || UI_LINES=24
    UI_COLS=$(cap cols);   [ -n "$UI_COLS" ]  || UI_COLS=80
    BOX_W=74
    [ "$UI_COLS" -lt 78 ] && BOX_W=$(( UI_COLS - 4 ))
    if [ "$BOX_W" -ge 74 ]; then
        TEMPLATE_LABELS=('nginx + fpm' 'apache + mod_php' 'nginx + apache')
    else
        TEMPLATE_LABELS=('nginx' 'apache' 'nginx+apache')
    fi
}

ui_cup_cache() {
    local n max=$UI_LINES
    [ "$max" -gt 80 ] && max=80
    UI_CUP=()
    for (( n = 0; n < max; n++ )); do
        UI_CUP[$n]=$(tput cup "$n" 0 2>/dev/null)
    done
}

# put <line> <text> - write one line in place and clear whatever it overwrote.
put() { printf '%s%s%s' "${UI_CUP[$1]}" "$2" "$T_EL"; }

# _pad <n> -> R_PAD
_pad() {
    if [ "$1" -le 0 ]; then R_PAD=''; else printf -v R_PAD '%*s' "$1" ''; fi
}

# _line <n> <char> -> R_LINE
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
    ui_size
    ui_cup_cache
    ui_layout
    UI_DIRTY=1
}

# _read_key -> KEY: up down left right enter backspace escape tab, or the character
_read_key() {
    local k rest
    IFS= read -rsn1 k < "$TTY" || return 1

    if [ "$k" == $'\033' ]; then
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
        '')            KEY=enter ;;
        $'\177'|$'\b') KEY=backspace ;;
        $'\t')         KEY=tab ;;
        *)             KEY=$k ;;
    esac
    return 0
}

#=[ box drawing ]======================================================================================================

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

# _box_sep [note] -> R_BOX. A note ("↑ 4 more") sits on the line, as a title.
_box_sep() {
    if [ -z "$1" ]; then
        _line $(( BOX_W - 2 )) "$G_H"
        R_BOX="${T_DIM}${G_ML}${R_LINE}${G_MR}${T_RESET}"
        return
    fi
    _line $(( BOX_W - ${#1} - 5 )) "$G_H"
    R_BOX="${T_DIM}${G_ML}${G_H} ${T_RESET}${T_GREY}$1${T_RESET}${T_DIM} ${R_LINE}${G_MR}${T_RESET}"
}

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

# _segments <current> <focused> <options...> -> R_SEG, R_SEG_PLAIN. Every
# choice on screen, the active one boxed, as install.sh.
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

# The form's lines, top to bottom. h:<x> is a section title. The Start button
# is cursor value ${#ITEMS[@]}, below the scrolling part.
ITEMS=(
    h:base base template php phpx remi email sshport sshkey tz adminmail skipupd
    h:addons account hosting modsec ftp clamav clamscan wireguard minecraft
    h:features api mcp deploy dnscluster
)
N_ITEMS=${#ITEMS[@]}

cursor=0
VP_TOP=0
FORM_ERROR=''
FORM_FAIL=''
REQAD_HERE=0
REQAD_VERSION=''
PHP_HAVE=''
TEMPLATE_CHOICES=("${TEMPLATES[@]}")

declare -a UI_SHOWN=()
UI_DIRTY=1

# Screen lines. Fixed: the box's top, OS and facts rows and two separators, the
# Start button and bottom, then help, description and error under the box (11
# with line 0). The wordmark shows only when every setting fits under it too;
# otherwise the settings scroll.
ui_layout() {
    ART_H=0
    if [ $(( UI_LINES - 11 - ART_ROWS - 1 )) -ge "$N_ITEMS" ]; then
        ART_H=$(( ART_ROWS + 1 ))
    fi
    VP_H=$(( UI_LINES - 11 - ART_H ))
    [ "$VP_H" -gt "$N_ITEMS" ] && VP_H=$N_ITEMS
    [ "$VP_H" -lt 1 ] && VP_H=1
    L_TOP=$(( 1 + ART_H ))
    L_OS=$(( L_TOP + 1 ))
    L_FACTS=$(( L_TOP + 2 ))
    L_SEP1=$(( L_TOP + 3 ))
    L_FIRST=$(( L_TOP + 4 ))
    L_SEP2=$(( L_FIRST + VP_H ))
    L_START=$(( L_SEP2 + 1 ))
    L_BOT=$(( L_START + 1 ))
    L_HELP=$(( L_BOT + 1 ))
    L_DESC=$(( L_BOT + 2 ))
    L_ERROR=$(( L_BOT + 3 ))
    [ "$VP_H" -ge 5 ]
}

gather_facts() {
    FACT_HOST=$(hostname 2>/dev/null)
    FACT_IP=$(ip address show 2>/dev/null | grep 'scope global' | grep 'inet ' | head -n 1 | awk '{print $2}' | awk -F/ '{print $1}')
    FACT_VCORES=$(grep -c '^processor' /proc/cpuinfo 2>/dev/null)
    FACT_MEM=$(free -hmw --si 2>/dev/null | grep 'Mem:' | awk '{print $2}')
}

# reqad_version -> REQAD_VERSION, shown next to the wordmark. Best effort: the
# installed package, otherwise what the repository offers; never blocks.
reqad_version() {
    REQAD_VERSION=$(rpm -q --qf '%{VERSION}' reqad 2>/dev/null)
    case $REQAD_VERSION in
        [0-9]*) return ;;
    esac
    REQAD_VERSION=''
    command -v curl >/dev/null 2>&1 || return
    command -v gzip >/dev/null 2>&1 || return

    local base="${REPO}/el${EL}/RPMS/x86_64" href newest
    href=$(curl -fsS --max-time 4 "${base}/repodata/repomd.xml" 2>/dev/null \
           | tr '<' '\n' | grep -m1 -o 'location href="[^"]*primary\.xml\.gz"' \
           | sed 's/.*href="//; s/"$//') || return
    [ -n "$href" ] || return
    newest=$(curl -fsS --max-time 6 "${base}/${href}" 2>/dev/null | gzip -dc 2>/dev/null \
             | grep -o 'reqad-[0-9][0-9.]*-[0-9]*\.el[0-9]*\.noarch\.rpm' \
             | sort -V | tail -n 1) || return
    [ -n "$newest" ] || return
    newest=${newest#reqad-}
    REQAD_VERSION=${newest%%-*}
}

# On a server that runs Reqad the form shows what is there: the base system
# is kept (its lines are dimmed) and installed add-ons stay as they are.
form_init() {
    if reqad_running; then
        REQAD_HERE=1
        ADDONS_ONLY=1
        local t
        t=$(ini_get template); [ -n "$t" ] && TEMPLATE=$t
        t=$(ini_get php);      [ -n "$t" ] && PHP_VERSION=$t
        [ "$(ini_get email)" == "1" ] && WITH_EMAIL=1 || WITH_EMAIL=0
        PHP_HAVE=",$(ini_get php_versions),"
        TEMPLATE_CHOICES=("$TEMPLATE")
        [ "$TEMPLATE" == "nginx_php-fpm" ] && TEMPLATE_CHOICES+=(nginx_apache_php-fpm)
        remi_is_local && { REMI_DONE=1; REMI_LOCAL=1; }
        rpm -q --quiet reqad-account      && { I_ACCOUNT=1;   A_ACCOUNT=1; }
        rpm -q --quiet reqad-hosting-pro  && { I_HOSTING=1;   A_HOSTING=1; }
        rpm -q --quiet reqad-modsecurity  && { I_MODSEC=1;    A_MODSEC=1; }
        rpm -q --quiet reqad-pureftpd     && { I_FTP=1;       A_FTP=1; }
        rpm -q --quiet reqad-clamav       && { I_CLAMAV=1;    A_CLAMAV=1; }
        rpm -q --quiet reqad-wireguard    && { I_WIREGUARD=1; A_WIREGUARD=1; }
        rpm -q --quiet reqad-minecraft    && { I_MINECRAFT=1; A_MINECRAFT=1; }
        [ "$(ini_get dnscluster)" == "1" ] && F_DNSCLUSTER=1
    fi
    cursor=0
    form_move 1
}

# _item_var <item> -> R_VAR: the yes/no variable of a toggle line, or empty
_item_var() {
    case $1 in
        email)      R_VAR=WITH_EMAIL ;;
        skipupd)    R_VAR=SKIP_UPDATE ;;
        account)    R_VAR=A_ACCOUNT ;;
        hosting)    R_VAR=A_HOSTING ;;
        modsec)     R_VAR=A_MODSEC ;;
        ftp)        R_VAR=A_FTP ;;
        clamav)     R_VAR=A_CLAMAV ;;
        wireguard)  R_VAR=A_WIREGUARD ;;
        minecraft)  R_VAR=A_MINECRAFT ;;
        api)        R_VAR=F_API ;;
        mcp)        R_VAR=F_MCP ;;
        deploy)     R_VAR=F_DEPLOY ;;
        dnscluster) R_VAR=F_DNSCLUSTER ;;
        *)          R_VAR='' ;;
    esac
}

# _item_installed <item>: an add-on that is on this server already
_item_installed() {
    case $1 in
        account)   [ -n "$I_ACCOUNT" ] ;;
        hosting)   [ -n "$I_HOSTING" ] ;;
        modsec)    [ -n "$I_MODSEC" ] ;;
        ftp)       [ -n "$I_FTP" ] ;;
        clamav)    [ -n "$I_CLAMAV" ] ;;
        wireguard) [ -n "$I_WIREGUARD" ] ;;
        minecraft) [ -n "$I_MINECRAFT" ] ;;
        *)         return 1 ;;
    esac
}

# _item_off <item>: a line the cursor skips (shown dimmed)
_item_off() {
    case $1 in
        h:*|base) return 0 ;;
        php|email|sshport|sshkey|tz|adminmail|skipupd) [ "$ADDONS_ONLY" -eq 1 ] ;;
        template)  [ "${#TEMPLATE_CHOICES[@]}" -le 1 ] ;;
        remi)      [ -n "$REMI_DONE" ] ;;
        clamscan)  [ "$A_CLAMAV" -eq 0 ] || [ "$WITH_EMAIL" -eq 0 ] || [ -n "$I_CLAMAV" ] ;;
        dnscluster) [ "$REQAD_HERE" -eq 1 ] && [ "$F_DNSCLUSTER" -eq 1 ] ;;
        *)         _item_installed "$1" ;;
    esac
}

_item_label() {
    case $1 in
        h:base)     R_LABEL='Base system' ;;
        h:addons)   R_LABEL='Add-ons' ;;
        h:features) R_LABEL='Features' ;;
        base)       R_LABEL='Reqad' ;;
        template)   R_LABEL='Web server' ;;
        php)        R_LABEL='PHP version' ;;
        phpx)       R_LABEL='More PHP' ;;
        remi)       R_LABEL='Remi repo' ;;
        email)      R_LABEL='Email stack' ;;
        sshport)    R_LABEL='SSH port' ;;
        sshkey)     R_LABEL='SSH key' ;;
        tz)         R_LABEL='Timezone' ;;
        adminmail)  R_LABEL='Admin email' ;;
        skipupd)    R_LABEL='Skip update' ;;
        account)    R_LABEL='Account panel' ;;
        hosting)    R_LABEL='Hosting Pro' ;;
        modsec)     R_LABEL='ModSecurity' ;;
        ftp)        R_LABEL='Pure-FTPd' ;;
        clamav)     R_LABEL='ClamAV' ;;
        clamscan)   R_LABEL='ClamAV scan' ;;
        wireguard)  R_LABEL='WireGuard' ;;
        minecraft)  R_LABEL='Minecraft' ;;
        api)        R_LABEL='REST API' ;;
        mcp)        R_LABEL='AI assistants' ;;
        deploy)     R_LABEL='Deployments' ;;
        dnscluster) R_LABEL='DNS cluster' ;;
    esac
}

# _template_label <value> -> R_TL
_template_label() {
    local i
    for i in "${!TEMPLATES[@]}"; do
        if [ "${TEMPLATES[$i]}" == "$1" ]; then R_TL=${TEMPLATE_LABELS[$i]}; return; fi
    done
    R_TL=$1
}

# _item_value <item> -> R_VAL: the value as plain text (text lines, dimmed lines)
_item_value() {
    local v
    case $1 in
        base)
            if [ "$REQAD_HERE" -eq 1 ]; then
                R_VAL="Reqad ${REQAD_VERSION} runs here: kept as it is"
            else
                R_VAL='install'
            fi ;;
        template)  _template_label "$TEMPLATE"; R_VAL=$R_TL ;;
        php)       R_VAL=$PHP_VERSION ;;
        phpx)      v=${PHP_EXTRA// /, }; R_VAL=${v:-none} ;;
        remi)      if [ "$REMI_LOCAL" -eq 1 ]; then R_VAL='local'; else R_VAL='mirror'; fi ;;
        sshport)   R_VAL=$SSH_PORT ;;
        sshkey)    R_VAL=${SSH_KEY_IN:-none} ;;
        tz)        R_VAL=$TIMEZONE ;;
        adminmail) R_VAL=${ADMIN_EMAIL:-none} ;;
        clamscan)  R_VAL=$CLAMAV_SCAN ;;
        *)
            _item_var "$1"
            if [ -n "$R_VAR" ] && [ "${!R_VAR}" -eq 1 ]; then R_VAL='yes'; else R_VAL='no'; fi ;;
    esac
}

# _item_note <item> -> R_NOTE: a short dim remark after the value
_item_note() {
    R_NOTE=''
    if _item_installed "$1"; then R_NOTE='installed'; return; fi
    case $1 in
        hosting)  [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && R_NOTE='needed by nginx + apache' ;;
        modsec)   [ "$TEMPLATE" == "apache_modphp" ] && [ "$A_MODSEC" -eq 1 ] && R_NOTE='skipped on apache + mod_php' ;;
        clamav)   [ "$WITH_EMAIL" -eq 0 ] && [ "$A_CLAMAV" -eq 1 ] && R_NOTE='skipped: no email stack' ;;
        template) [ "$REQAD_HERE" -eq 1 ] && [ "${#TEMPLATE_CHOICES[@]}" -le 1 ] && R_NOTE='kept as it is' ;;
        remi)     [ -n "$REMI_DONE" ] && R_NOTE='set already' ;;
        dnscluster) [ "$REQAD_HERE" -eq 1 ] && [ "$F_DNSCLUSTER" -eq 1 ] && R_NOTE='on already' ;;
    esac
}

# _item_desc <item> -> R_DESC: the line under the box for the focused line
_item_desc() {
    case $1 in
        template)
            case $TEMPLATE in
                nginx_php-fpm) R_DESC='nginx serves the sites; PHP runs in a PHP-FPM pool per account.' ;;
                apache_modphp) R_DESC='Apache with mod_php reads .htaccess; nginx serves only the panel.' ;;
                *)             R_DESC='nginx in front, Apache behind it reads .htaccess, PHP-FPM. Needs Hosting Pro.' ;;
            esac ;;
        php)       R_DESC='The PHP version new sites get; more versions on the next line.' ;;
        phpx)      R_DESC='More PHP versions next to the default one, for sites that need another.' ;;
        remi)
            if [ "$REMI_LOCAL" -eq 1 ]; then
                R_DESC="PHP packages from the copy of Remi's repository on repo.reqad.net."
            else
                R_DESC="PHP packages from Remi's public mirrors."
            fi ;;
        email)     R_DESC='Mail server (exim + dovecot), webmail and spam filtering. ClamAV needs it.' ;;
        sshport)   R_DESC='The port sshd listens on; the firewall is opened for it.' ;;
        sshkey)    R_DESC="root's public key, added only when root has no authorized_keys yet." ;;
        tz)        R_DESC='The server time zone, used by cron jobs, logs and the panel.' ;;
        adminmail) R_DESC="Address for the Let's Encrypt account (certificate expiry mails)." ;;
        skipupd)   R_DESC='Skip the first dnf update and package install (the server is up to date).' ;;
        account)   R_DESC='A control panel for each hosting account, on port 2083 (reqad-account).' ;;
        hosting)   R_DESC='Packages and limits, disk and mail quotas, extra IPs (reqad-hosting-pro).' ;;
        modsec)    R_DESC='Web application firewall in nginx, in front of the sites (reqad-modsecurity).' ;;
        ftp)       R_DESC='FTP accounts for the hosting accounts, with Pure-FTPd (reqad-pureftpd).' ;;
        clamav)    R_DESC='Virus scanning of mail, with extra signature feeds (reqad-clamav).' ;;
        clamscan)
            case $CLAMAV_SCAN in
                none) R_DESC='Installed but not used yet: choose what it does on the Email page.' ;;
                tag)  R_DESC='Mail with a virus is delivered, with headers saying so.' ;;
                deny) R_DESC='Mail with a virus is refused.' ;;
            esac ;;
        wireguard) R_DESC='WireGuard VPN peers, managed from the panel (reqad-wireguard).' ;;
        minecraft) R_DESC='A Minecraft Java server, managed from the panel (reqad-minecraft).' ;;
        api)       R_DESC='REST API: it stays off until turned on in Settings > API access.' ;;
        mcp)       R_DESC='AI assistants over SSH (MCP): off until turned on in Settings > AI access.' ;;
        deploy)    R_DESC='Git deployments and Node.js / Python apps (Accounts > Deployments).' ;;
        dnscluster) R_DESC='Only for the primary server of a DNS cluster: installs PowerDNS.' ;;
        start)
            if [ "$DRY_RUN" -eq 1 ]; then
                R_DESC='Dry run: shows what would be done, changes nothing.'
            else
                R_DESC="Installs with these choices; the log is in ${WORKDIR}."
            fi ;;
        *)         R_DESC='' ;;
    esac
}

# _row_text <index> -> R_ROW, R_ROW_PLAIN: the inside of one line of the box
_row_text() {
    local idx=$1 item=${ITEMS[$1]} sel=0 base='' body plain marker
    _item_label "$item"

    if [[ $item == h:* ]]; then
        R_ROW=" ${T_BOLD}${T_LOGO2}${R_LABEL}${T_RESET}"
        R_ROW_PLAIN=" ${R_LABEL}"
        return
    fi

    [ "$cursor" -eq "$idx" ] && sel=1
    [ "$sel" -eq 1 ] && base="${T_BG_SEL}${T_FG_SEL}"

    if _item_off "$item"; then
        _item_value "$item"
        _item_note "$item"
        body="${T_VAL_OFF}${R_VAL}${T_RESET}"; plain=$R_VAL
        if [ -n "$R_NOTE" ] && [ "$item" != "base" ]; then
            body="${body}  ${T_DIM}${R_NOTE}${T_RESET}"; plain="${plain}  ${R_NOTE}"
        fi
        printf -v R_ROW       '   %s%-13s%s %s' "$T_DIM" "$R_LABEL" "$T_RESET" "$body"
        printf -v R_ROW_PLAIN '   %-13s %s' "$R_LABEL" "$plain"
        return
    fi

    case $item in
        template)
            local t
            local -a labels=()
            for t in "${TEMPLATE_CHOICES[@]}"; do
                _template_label "$t"; labels+=("$R_TL")
            done
            _template_label "$TEMPLATE"
            _segments "$R_TL" "$sel" "${labels[@]}"
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        php)
            _segments "$PHP_VERSION" "$sel" "${PHP_VERSIONS[@]}"
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        remi)
            _item_value remi
            _segments "$R_VAL" "$sel" mirror local
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        clamscan)
            _segments "$CLAMAV_SCAN" "$sel" "${CLAMAV_MODES[@]}"
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
        phpx|sshport|sshkey|tz|adminmail)
            local hint='' room
            if [ "$sel" -eq 1 ]; then
                hint='to edit'
                [ "$item" == "tz" ] && hint='to search'
                [ "$item" == "phpx" ] && hint='to choose'
            fi
            _item_value "$item"
            # a long value (a key) is cut in the middle to leave room for the hint
            room=$(( BOX_W - 2 - 17 - ${#hint} - 6 ))
            [ ${#R_VAL} -gt "$room" ] && R_VAL="${R_VAL:0:$(( room / 2 ))}${G_ELL}${R_VAL: -$(( room - room / 2 - 1 ))}"
            body="${T_WHITE}${T_BOLD}${R_VAL}${T_RESET}${base}"; plain=$R_VAL
            if [ "$sel" -eq 1 ]; then
                body="${body}   ${T_DIM}${G_ENTER} ${hint}${T_RESET}${base}"
                plain="${plain}   ${G_ENTER} ${hint}"
            fi ;;
        *)
            _item_value "$item"
            _segments "$R_VAL" "$sel" no yes
            body=$R_SEG; plain=$R_SEG_PLAIN ;;
    esac

    _item_note "$item"
    if [ -n "$R_NOTE" ]; then
        body="${body}  ${T_DIM}${R_NOTE}${T_RESET}${base}"; plain="${plain}  ${R_NOTE}"
    fi

    if [ "$sel" -eq 1 ]; then
        marker="${T_YELLOW}${G_ARROW}${T_RESET}${base}"
    else
        marker=' '
    fi
    printf -v R_ROW       '%s %s %-13s %s' "$base" "$marker" "$R_LABEL" "$body"
    printf -v R_ROW_PLAIN ' %s %-13s %s'   ' ' "$R_LABEL" "$plain"
}

_start_text() {
    local inner pad label='Start installation'
    [ "$DRY_RUN" -eq 1 ] && label='Show the plan (dry run)'
    if [ "$cursor" -eq "$N_ITEMS" ]; then
        inner=" ${G_ARROW} ${label} ${G_ARROW2} "
    else
        inner="   ${label}   "
    fi
    _pad $(( (BOX_W - 2 - ${#inner}) / 2 ))
    pad=$R_PAD
    if [ "$cursor" -eq "$N_ITEMS" ]; then
        R_ROW="${pad}${T_BTN_SEL}${inner}${T_RESET}"
    else
        R_ROW="${pad}${T_BTN}${inner}${T_RESET}"
    fi
    R_ROW_PLAIN="${pad}${inner}"
}

# _fit <text> <width> -> R_FIT: cut to the width, with an ellipsis when cut
_fit() {
    if [ ${#1} -le "$2" ]; then R_FIT=$1; else R_FIT="${1:0:$(( $2 - 1 ))}${G_ELL}"; fi
}

# paint <line> <text> - write the line only when it differs from what is there.
paint() {
    if [ "$UI_DIRTY" -eq 1 ] || [ "${UI_SHOWN[$1]}" != "$2" ]; then
        put "$1" "$2"
        UI_SHOWN[$1]=$2
    fi
}

# form_move <delta>: to the next line that can be changed, or the Start button
form_move() {
    local i=$cursor
    while :; do
        i=$(( i + $1 ))
        [ "$i" -lt 0 ] && return
        if [ "$i" -ge "$N_ITEMS" ]; then cursor=$N_ITEMS; return; fi
        _item_off "${ITEMS[$i]}" || { cursor=$i; return; }
    done
}

# Keep the focused line inside the viewport (with its section title when it is
# the first line of a section); on the Start button, show the end.
form_scroll() {
    local max=$(( N_ITEMS - VP_H ))
    [ "$max" -lt 0 ] && max=0
    if [ "$cursor" -ge "$N_ITEMS" ]; then
        VP_TOP=$max
        return
    fi
    # the dimmed lines right above it, up to its section title, come along
    local top=$cursor prev
    while [ "$top" -gt 0 ]; do
        prev=${ITEMS[$(( top - 1 ))]}
        _item_off "$prev" || break
        top=$(( top - 1 ))
        [[ $prev == h:* ]] && break
    done
    [ "$cursor" -lt "$VP_TOP" ] && VP_TOP=$cursor
    [ "$top" -lt "$VP_TOP" ] && [ $(( cursor - top )) -lt "$VP_H" ] && VP_TOP=$top
    [ "$cursor" -ge $(( VP_TOP + VP_H )) ] && VP_TOP=$(( cursor - VP_H + 1 ))
    [ "$VP_TOP" -gt "$max" ] && VP_TOP=$max
    [ "$VP_TOP" -lt 0 ] && VP_TOP=0
}

draw_form() {
    local i s idx facts more

    form_scroll

    if [ "$UI_DIRTY" -eq 1 ]; then
        printf '%s' "$T_CLEAR"
        UI_SHOWN=()

        if [ "$ART_H" -gt 0 ]; then
            local -a shades=("$T_LOGO1" "$T_LOGO2" "$T_LOGO2" "$T_LOGO3" "$T_LOGO4" "$T_LOGO4")
            local tag="    ${T_AMBER}${T_BOLD}Premium${T_RESET}"
            [ -n "$REQAD_VERSION" ] && tag="${tag}  ${T_GREY}v${REQAD_VERSION}${T_RESET}"
            for (( i = 0; i < ART_ROWS; i++ )); do
                if [ "$i" -eq 4 ]; then
                    paint $(( i + 1 )) "  ${T_BOLD}${shades[$i]}${ART[$i]}${T_RESET}${tag}"
                else
                    paint $(( i + 1 )) "  ${T_BOLD}${shades[$i]}${ART[$i]}${T_RESET}"
                fi
            done
            paint $ART_H ''
            _box_top ''
        else
            _box_top "${T_AMBER}${T_BOLD}Reqad Premium${T_RESET}${T_DIM}" 'Reqad Premium'
        fi
        paint $L_TOP "$R_BOX"

        facts="$FACT_HOST"
        [ -n "$FACT_IP" ]     && facts="$facts   $FACT_IP"
        [ -n "$FACT_VCORES" ] && facts="$facts   ${FACT_VCORES} vCPU"
        [ -n "$FACT_MEM" ]    && facts="$facts   ${FACT_MEM} RAM"
        [ "$USE_TEST" -eq 1 ] && facts="$facts   (test repository)"

        _box_row " ${T_GREY}${OS_PRETTY} ${G_DOT} ${ARCH}${T_RESET}" " ${OS_PRETTY} ${G_DOT} ${ARCH}"
        paint $L_OS "$R_BOX"
        _box_row " ${T_DIM}${facts}${T_RESET}" " ${facts}"
        paint $L_FACTS "$R_BOX"
        _box_bot;  paint $L_BOT "$R_BOX"
        paint $L_HELP "  ${T_HELP}${G_UD} move ${G_DOT} ${G_LR} change ${G_DOT} ${G_ENTER} select ${G_DOT} q quit${T_RESET}"
    fi

    more=''
    [ "$VP_TOP" -gt 0 ] && more="${G_UP} ${VP_TOP} more"
    _box_sep "$more"; paint $L_SEP1 "$R_BOX"

    for (( i = 0; i < VP_H; i++ )); do
        idx=$(( VP_TOP + i ))
        if [ "$idx" -lt "$N_ITEMS" ]; then
            s=0; [ "$cursor" -eq "$idx" ] && s=1
            _row_text "$idx"
            _box_row "$R_ROW" "$R_ROW_PLAIN" "$s"
        else
            _box_row '' ''
        fi
        paint $(( L_FIRST + i )) "$R_BOX"
    done

    more=''
    s=$(( N_ITEMS - VP_TOP - VP_H ))
    [ "$s" -gt 0 ] && more="${G_DN} ${s} more"
    _box_sep "$more"; paint $L_SEP2 "$R_BOX"

    _start_text
    _box_row "$R_ROW" "$R_ROW_PLAIN" 0
    paint $L_START "$R_BOX"

    if [ "$cursor" -ge "$N_ITEMS" ]; then _item_desc start; else _item_desc "${ITEMS[$cursor]}"; fi
    _fit "$R_DESC" $(( UI_COLS - 3 ))
    paint $L_DESC "  ${T_GREY}${R_FIT}${T_RESET}"

    if [ -n "$FORM_ERROR" ]; then
        _fit "$FORM_ERROR" $(( UI_COLS - 5 ))
        paint $L_ERROR "  ${T_RED}${G_CROSS} ${R_FIT}${T_RESET}"
    else
        paint $L_ERROR ''
    fi

    UI_DIRTY=0
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
    local i=0 item
    for item in "$@"; do
        if [ "$item" == "$needle" ]; then R_IDX=$i; return; fi
        i=$(( i + 1 ))
    done
    R_IDX=0
}

# form_change <delta>: left/right/space on the focused line
form_change() {
    local item=${ITEMS[$cursor]}
    case $item in
        template)
            _cycle TEMPLATE_CHOICES "$TEMPLATE" "$1"; TEMPLATE=$R_CYC
            # Apache behind nginx is Hosting Pro's
            [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && A_HOSTING=1 ;;
        php)      _cycle PHP_VERSIONS "$PHP_VERSION" "$1"; PHP_VERSION=$R_CYC ;;
        remi)     REMI_LOCAL=$(( 1 - REMI_LOCAL )) ;;
        clamscan) _cycle CLAMAV_MODES "$CLAMAV_SCAN" "$1"; CLAMAV_SCAN=$R_CYC ;;
        hosting)
            if [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && [ "$A_HOSTING" -eq 1 ]; then
                FORM_ERROR='nginx + apache needs Hosting Pro'
            else
                A_HOSTING=$(( 1 - A_HOSTING ))
            fi ;;
        *)
            _item_var "$item"
            [ -n "$R_VAR" ] && printf -v "$R_VAR" '%s' $(( 1 - ${!R_VAR} )) ;;
    esac
}

# edit_text <title> <prompt> <value> <check> <digits|any> -> R_TEXT
# A one-line editor over the form. <check> is a function that returns 0 for a
# good value, else sets R_ERR. A long value (a pasted key) shows its end.
edit_text() {
    local title=$1 prompt=$2 buf=$3 check=$4 allowed=$5
    local err='' prev_buf=$'\001' prev_err='x' show room

    printf '%s' "$T_CLEAR"
    _box_top "${T_YELLOW}${T_BOLD}${title}${T_RESET}${T_DIM}" "$title"; put 1 "$R_BOX"
    _box_row '' '';                                                     put 2 "$R_BOX"
    _box_row '' '';                                                     put 4 "$R_BOX"
    _box_bot;                                                           put 5 "$R_BOX"
    if [ "$allowed" == "digits" ]; then
        put 7 "  ${T_HELP}type a number ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"
    else
        put 7 "  ${T_HELP}type or paste ${G_DOT} empty = none ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"
    fi
    room=$(( BOX_W - 2 - 2 - ${#prompt} - 2 - 1 ))

    while :; do
        if [ "$buf" != "$prev_buf" ]; then
            show=$buf
            [ ${#show} -gt "$room" ] && show="${G_ELL}${show: -$(( room - 1 ))}"
            _box_row "  ${T_GREY}${prompt}:${T_RESET} ${T_WHITE}${T_BOLD}${show}${T_RESET}${T_YELLOW}${G_CUR}${T_RESET}" "  ${prompt}: ${show}${G_CUR}"
            put 3 "$R_BOX"
            prev_buf=$buf
        fi
        if [ "$err" != "$prev_err" ]; then
            if [ -n "$err" ]; then put 6 "  ${T_RED}${G_CROSS} ${err}${T_RESET}"; else put 6 ''; fi
            prev_err=$err
        fi

        _read_key || { UI_DIRTY=1; return 1; }
        case $KEY in
            escape) UI_DIRTY=1; return 1 ;;
            enter)
                buf=${buf##+([[:space:]])}; buf=${buf%%+([[:space:]])}
                if "$check" "$buf"; then
                    R_TEXT=$buf
                    UI_DIRTY=1
                    return 0
                fi
                prev_buf=$'\001'
                err=$R_ERR ;;
            backspace) buf=${buf%?}; err='' ;;
            up|down|left|right|tab|unknown) ;;
            *)
                if [ ${#KEY} -eq 1 ]; then
                    if [ "$allowed" == "digits" ]; then
                        [[ $KEY == [0-9] ]] && buf="${buf}${KEY}"
                    else
                        buf="${buf}${KEY}"
                    fi
                    err=''
                fi ;;
        esac
    done
}

check_port()  { valid_port "$1" && return 0; R_ERR="'$1' is not a port between 1 and 65535"; return 1; }
check_key()   { { [ -z "$1" ] || valid_ssh_key "$1"; } && return 0; R_ERR='not an SSH public key (ssh-ed25519 AAAA... comment)'; return 1; }
check_email() { { [ -z "$1" ] || valid_email "$1"; } && return 0; R_ERR="'$1' is not an email address"; return 1; }

# edit_php_extra: a checklist of the versions PHP Settings > Install PHP offers.
# The default version and those installed already are shown, not ticked.
edit_php_extra() {
    local -a vers=()
    local -A pick=()
    local v i sel=0 n line plain mark base marker fixed
    [ "$EL" -eq 8 ] && vers=(5.4)
    vers+=("${PHP_EXTRA_VERSIONS[@]}")
    n=${#vers[@]}
    for v in $PHP_EXTRA; do pick[$v]=1; done

    printf '%s' "$T_CLEAR"
    _box_top "${T_YELLOW}${T_BOLD}More PHP versions${T_RESET}${T_DIM}" 'More PHP versions'; put 1 "$R_BOX"
    _box_bot;                                                        put $(( 2 + n )) "$R_BOX"
    put $(( 4 + n )) "  ${T_HELP}${G_UD} move ${G_DOT} space pick ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"

    while :; do
        for (( i = 0; i < n; i++ )); do
            v=${vers[$i]}
            base=''; marker=' '
            if [ "$i" -eq "$sel" ]; then
                base="${T_BG_SEL}${T_FG_SEL}"
                marker="${T_YELLOW}${G_ARROW}${T_RESET}${base}"
            fi
            fixed=''
            [[ $PHP_HAVE == *", ${v},"* || $PHP_HAVE == *",${v},"* ]] && fixed='installed'
            [ "$v" == "$PHP_VERSION" ] && fixed='default'
            if [ -n "$fixed" ]; then
                line="${base} ${marker}     ${T_DIM}PHP ${v}  ${fixed}${T_RESET}${base}"
                plain="       PHP ${v}  ${fixed}"
            else
                if [ -n "${pick[$v]}" ]; then
                    mark='[x]'
                    line="${base} ${marker} ${T_GREEN}${T_BOLD}${mark}${T_RESET}${base} ${T_WHITE}${T_BOLD}PHP ${v}${T_RESET}${base}"
                else
                    mark='[ ]'
                    line="${base} ${marker} ${T_DIM}${mark}${T_RESET}${base} ${T_GREY}PHP ${v}${T_RESET}${base}"
                fi
                plain="   ${mark} PHP ${v}"
            fi
            if [ "$i" -eq "$sel" ]; then _box_row "$line" "$plain" 1; else _box_row "$line" "$plain"; fi
            put $(( 2 + i )) "$R_BOX"
        done

        _read_key || { UI_DIRTY=1; return 1; }
        case $KEY in
            escape) UI_DIRTY=1; return 1 ;;
            up|k)   [ "$sel" -gt 0 ] && sel=$(( sel - 1 )) ;;
            down|j|tab) [ "$sel" -lt $(( n - 1 )) ] && sel=$(( sel + 1 )) ;;
            ' '|x|left|right)
                v=${vers[$sel]}
                if [ "$v" != "$PHP_VERSION" ] && [[ $PHP_HAVE != *", ${v},"* && $PHP_HAVE != *",${v},"* ]]; then
                    if [ -n "${pick[$v]}" ]; then unset "pick[$v]"; else pick[$v]=1; fi
                fi ;;
            enter)
                PHP_EXTRA=''
                for v in "${vers[@]}"; do
                    [ -n "${pick[$v]}" ] && PHP_EXTRA="${PHP_EXTRA:+$PHP_EXTRA }$v"
                done
                UI_DIRTY=1
                return 0 ;;
        esac
    done
}

# edit_timezone: type to filter, arrows to choose, enter to accept (install.sh's)
edit_timezone() {
    local -a zones matches
    local filter='' i start shown sel=0
    local prev_filter='x' prev_sel=-1 prev_start=-1 prev_count=-1

    mapfile -t zones < <(list_timezones)
    [ ${#zones[@]} -gt 0 ] || { FORM_ERROR='no timezone database found'; return 1; }

    # "tok" offers Asia/Tokyo before Antarctica/Vostok: city prefix first
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

        f=${f//_/}; f=${f// /}

        local zn cityn
        for z in "${zones[@]}"; do
            city=${z##*/}
            zn=${z,,};       zn=${zn//_/}
            cityn=${city,,}; cityn=${cityn//_/}
            if [[ $cityn == "$f"* ]]; then
                tier1+=("$z")
            elif [[ $zn == "$f"* ]]; then
                tier2+=("$z")
            elif [[ $zn == *"$f"* ]]; then
                tier3+=("$z")
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
    put $(( 4 + shown + 3 )) "  ${T_HELP}type to filter ${G_DOT} ${G_UD} choose ${G_DOT} ${G_ENTER} confirm ${G_DOT} esc cancel${T_RESET}"

    while :; do
        if [ "$filter" != "$prev_filter" ]; then
            _box_row "  ${T_GREY}Search:${T_RESET} ${T_WHITE}${T_BOLD}${filter}${T_RESET}${T_YELLOW}${G_CUR}${T_RESET}" "  Search: ${filter}${G_CUR}"
            put 2 "$R_BOX"
            prev_filter=$filter
        fi

        start=0
        [ $sel -ge $shown ] && start=$(( sel - shown + 1 ))

        if [ $sel -ne $prev_sel ] || [ $start -ne $prev_start ] || [ ${#matches[@]} -ne $prev_count ]; then
            if [ ${#matches[@]} -eq 0 ]; then
                _box_row "  ${T_RED}no timezone matches '${filter}'${T_RESET}" "  no timezone matches '${filter}'"
                put 4 "$R_BOX"
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
    if [ -d /usr/share/zoneinfo ]; then
        find /usr/share/zoneinfo -type f -printf '%P\n' 2>/dev/null \
            | grep -E '^[A-Z][A-Za-z_]+/' | sort
    fi
}

run_form() {
    has_tty || return 1
    if ! screen_setup; then
        FORM_FAIL="the terminal is too small for the installer's form (at least 64 columns
        and 16 lines): make the window bigger, or pass the options instead (--help)"
        return 1
    fi

    FORM_ERROR=''
    UI_DIRTY=1

    while :; do
        draw_form
        _read_key || { screen_restore; return 1; }
        FORM_ERROR=''

        case $KEY in
            q|Q) screen_restore; return 1 ;;

            up|k)       form_move -1 ;;
            down|j|tab) form_move 1 ;;

            left|h)  [ "$cursor" -lt "$N_ITEMS" ] && form_change -1 ;;
            right|l) [ "$cursor" -lt "$N_ITEMS" ] && form_change 1 ;;
            ' ')     [ "$cursor" -lt "$N_ITEMS" ] && form_change 1 ;;

            enter)
                if [ "$cursor" -ge "$N_ITEMS" ]; then
                    screen_restore
                    return 0
                fi
                case ${ITEMS[$cursor]} in
                    phpx)      edit_php_extra ;;
                    sshport)   edit_text 'SSH port' 'Port' "$SSH_PORT" check_port digits && SSH_PORT=$R_TEXT ;;
                    sshkey)    edit_text "root's SSH public key" 'Key' "$SSH_KEY_IN" check_key any && SSH_KEY_IN=$R_TEXT ;;
                    tz)        edit_timezone ;;
                    adminmail) edit_text 'Admin email' 'Address' "$ADMIN_EMAIL" check_email any && ADMIN_EMAIL=$R_TEXT ;;
                    *)         form_change 1 ;;
                esac ;;
        esac
    done
}

#=[ steps ]============================================================================================================

fetch() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsSL -o "$2" "$1"
    else
        wget -q -O "$2" "$1"
    fi
}

run_base() {
    local script="$BASE_SCRIPT"
    if [ -z "$script" ] || [[ "$script" =~ ^https?:// ]]; then
        local src="${script:-${REPO}/install.sh}"
        script="${WORKDIR}/install.sh"
        fetch "$src" "$script" || die "could not download ${src}"
        [ -s "$script" ] || die "downloaded an empty install.sh"
    fi
    [ -f "$script" ] || die "no such file: ${script}"

    # Apache behind nginx starts as nginx_php-fpm; Hosting Pro switches it below
    local base_template="$TEMPLATE"
    [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && base_template='nginx_php-fpm'
    local args=(--template "$base_template" --php "$PHP_VERSION" --ssh-port "$SSH_PORT" --timezone "$TIMEZONE")
    [ "$WITH_EMAIL" -eq 1 ] && args+=(--email) || args+=(--no-email)
    [ "$SKIP_UPDATE" -eq 1 ] && args+=(--skip-update)
    # install.sh runs install-el.sh with --script; the local copy of it, if any
    [ -n "$BASE_SCRIPT" ] && [ -f "$(dirname "$BASE_SCRIPT")/install-el.sh" ] \
        && args+=(--script "$(dirname "$BASE_SCRIPT")/install-el.sh")

    echo -e "\n${BOLD}Base system${NC} ${GREY}(install.sh ${args[*]})${NC}\n"
    # install-el.sh reads these from the environment
    local rc=0
    SSH_KEY="$SSH_KEY_IN" EMAIL="$ADMIN_EMAIL" REQAD_REPO="$REPO" bash "$script" "${args[@]}" || rc=$?

    # A failing base install has already said why (install-el.sh exits 1 after
    # its own message), and when it stops at a check there may be no log at all.
    if [ "$rc" -ne 0 ]; then
        printf "\n Premium add-ons not installed: the base install stopped (see above).\n\n" >&2
        exit 1
    fi
    if ! reqad_running; then
        local log="${WORKDIR}/install_reqad.log"
        [ -s "$log" ] && die "Reqad is not running after the base install - see ${log}"
        die "Reqad is not running after the base install"
    fi
}

# Remi's repositories (remi, remi-modular, remi-safe) read from our copy on
# repo.reqad.net, refreshed daily from Remi, instead of Remi's public mirrors.
# Done before the base install: remi-release comes from the copy too, and
# install-el.sh then finds it installed. A remi-release update later keeps the
# changed .repo files (they are %config(noreplace)).
remi_is_local() {
    grep -qs "^baseurl=${REMI_LOCAL_URL}/" /etc/yum.repos.d/remi.repo
}

remi_local() {
    if remi_is_local; then
        say "Remi repository" OK "the copy on ${REPO#https://} already"
        return 0
    fi
    [ -f /etc/yum.repos.d/epel.repo ] || dnf -y install epel-release >> "$LOG" 2>&1
    rpm -q --quiet dnf-plugins-core || dnf -y install dnf-plugins-core >> "$LOG" 2>&1
    if ! rpm -q --quiet remi-release; then
        # as install-el.sh's remi-release from a URL: Remi's key comes with it
        dnf -y install --repofrompath="reqad-remi,${REMI_LOCAL_URL}/${EL}/remi/x86_64/" \
            --setopt=reqad-remi.gpgcheck=0 --repo=reqad-remi remi-release >> "$LOG" 2>&1
    fi
    if ! rpm -q --quiet remi-release; then
        say "Remi repository" ERROR "remi-release did not install, see ${LOG}"
        exit 1
    fi
    local r ok=1
    for r in remi:remi remi-modular:modular remi-safe:safe; do
        dnf config-manager --save --setopt="${r%%:*}.mirrorlist=" \
            --setopt="${r%%:*}.baseurl=${REMI_LOCAL_URL}/${EL}/${r#*:}/\$basearch/" >> "$LOG" 2>&1 || ok=0
    done
    if [ "$ok" -eq 1 ] && remi_is_local && dnf -y makecache --repo remi --repo remi-safe >> "$LOG" 2>&1; then
        say "Remi repository" DONE "the copy on ${REPO#https://}"
        result "Remi repository" "the copy on ${REPO#https://}"
    else
        say "Remi repository" ERROR "see ${LOG}"
        exit 1
    fi
}

# Every server gets root's prompt (red user, green host, cyan folder) and
# vi = vim, in a marked block of /root/.bashrc so a second run does not add it
# again
root_prompt_is_set() {
    grep -qs '^# BEGIN reqad-root-prompt' /root/.bashrc
}

root_prompt() {
    echo -e "\n${BOLD}Root shell${NC}\n"
    if root_prompt_is_set; then
        say "Root prompt" OK "set already"
        return 0
    fi
    rpm -q --quiet vim-enhanced || dnf -y install vim-enhanced >> "$LOG" 2>&1
    cat >> /root/.bashrc <<'BASHRC'

# BEGIN reqad-root-prompt
alias vi='vim'
PS1='\[\033[01;31m\]\u\[\033[01;90m\].\[\033[01;32m\]\h \[\033[01;96m\]\w \[\033[01;90m\]\$ \[\033[00m\]'
# END reqad-root-prompt
BASHRC
    say "Root prompt" DONE "from the next login"
    result "Root prompt" "set (from the next login)"
}

# reqad-repo 1.0.3 and newer carry the [reqad-premium] section (switched off)
enable_premium_repo() {
    local f=/etc/yum.repos.d/reqad.repo
    dnf -y upgrade reqad-repo >> "$LOG" 2>&1
    if ! grep -q '^\[reqad-premium\]' "$f" 2>/dev/null; then
        say "Premium repository" ERROR "no [reqad-premium] in ${f}: reqad-repo 1.0.3 or newer is needed"
        exit 1
    fi
    if ! dnf config-manager --set-enabled reqad-premium >> "$LOG" 2>&1; then
        sed -i '/^\[reqad-premium\]/,/^\[/{s/^enabled=.*/enabled=1/}' "$f"
    fi
    [ "$USE_TEST" -eq 1 ] && DNF_REPOS=(--enablerepo=reqad-test)
    dnf -y makecache "${DNF_REPOS[@]}" >> "$LOG" 2>&1
    say "Premium repository" DONE
}

install_addons() {
    echo -e "\n${BOLD}Add-ons${NC}\n"

    if [ "$A_HOSTING" -eq 1 ]; then
        addon "Hosting Pro" reqad-hosting-pro
        if [ "$TEMPLATE" == "nginx_apache_php-fpm" ] && rpm -q --quiet reqad-hosting-pro; then
            if [ "$(ini_get template)" == "nginx_apache_php-fpm" ]; then
                say "Apache behind nginx" OK "already"
            elif "${RPATH}/bin/reqad" webserver switch nginx_apache_php-fpm >> "$LOG" 2>&1; then
                say "Apache behind nginx" DONE
                result "Web server" "Apache behind nginx"
            else
                say "Apache behind nginx" ERROR "see ${LOG}; try: reqad webserver switch nginx_apache_php-fpm"
                result "Web server" "FAILED to switch to Apache behind nginx"
            fi
        fi
    else
        result "Hosting Pro" "left out"
    fi

    if [ "$A_ACCOUNT" -eq 1 ]; then
        addon "Account panel (2083)" reqad-account
    else
        result "Account panel (2083)" "left out"
    fi

    if [ "$A_MODSEC" -eq 1 ]; then
        # it runs in nginx in front of the sites; on apache_modphp nginx serves only the panel
        if [ "$(ini_get template)" == "apache_modphp" ]; then
            say "ModSecurity" SKIPPED "needs nginx in front (not on apache_modphp)"
            result "ModSecurity" "skipped: needs nginx in front of the sites"
        else
            addon "ModSecurity" reqad-modsecurity
        fi
    else
        result "ModSecurity" "left out"
    fi

    if [ "$A_FTP" -eq 1 ]; then
        addon "Pure-FTPd" reqad-pureftpd
    else
        result "Pure-FTPd" "left out"
    fi

    if [ "$A_CLAMAV" -eq 1 ]; then
        if [ "$(ini_get email)" != "1" ]; then
            say "ClamAV" SKIPPED "the mail server is not installed"
            result "ClamAV" "skipped: no mail server"
        elif rpm -q --quiet reqad-clamav; then
            say "ClamAV" OK "already installed"
            result "ClamAV" "already installed"
        else
            # the upstream engine, then reqad-clamav and the signature feeds
            local tier=prod
            [ "$USE_TEST" -eq 1 ] && tier=test
            if REQAD_TIER=$tier WITH_SIGS=yes SCAN_MODE="$CLAMAV_SCAN" bash "${RPATH}/scripts/install/install-clamav.sh" >> "$LOG" 2>&1 \
               && rpm -q --quiet reqad-clamav; then
                say "ClamAV" DONE
                result "ClamAV" "installed (scan: ${CLAMAV_SCAN})"
            else
                say "ClamAV" ERROR "see ${WORKDIR}/install_clamav.log"
                result "ClamAV" "FAILED (see ${WORKDIR}/install_clamav.log)"
            fi
        fi
    else
        result "ClamAV" "left out"
    fi

    [ "$A_WIREGUARD" -eq 1 ] && addon "WireGuard" reqad-wireguard
    [ "$A_MINECRAFT" -eq 1 ] && addon "Minecraft" reqad-minecraft
    return 0
}

# One more PHP version, as PHP Settings > Install PHP does it (ajax-php-install)
php_extra_install() {
    local v=$1 s=${1//./} label="PHP $1"
    if echo ",$(ini_get php_versions)," | grep -q ",${v},"; then
        say "$label" OK "already installed"
        return 0
    fi
    local mods pkgs m
    mods=$($RPHP -r 'require "/usr/local/reqad/app/functions/php.php"; echo implode(" ", php_install_modules($argv[1]));' "$v" 2>>"$LOG")
    pkgs="php${s}-php-fpm php${s}-php-cli php${s}-php-common"
    for m in $mods; do pkgs="${pkgs} php${s}-${m}"; done
    # shellcheck disable=SC2086
    if ! dnf_install $pkgs; then
        say "$label" ERROR "see ${LOG}"
        result "$label" "FAILED (see ${LOG})"
        return 1
    fi
    printf '[www]\nuser = nobody\ngroup = nobody\nlisten = /run/php-fpm-default_php%s.sock\nlisten.allowed_clients = 127.0.0.1\nlisten.owner = nobody\nlisten.group = nobody\nlisten.mode = 0000\n\npm = ondemand\npm.max_children = 1\npm.start_servers = 0\npm.min_spare_servers = 0\npm.max_spare_servers = 0\n' \
        "$s" > "/etc/opt/remi/php${s}/php-fpm.d/www.conf"
    systemctl enable --now "php${s}-php-fpm" >> "$LOG" 2>&1
    ini_list_add php_versions "$v"
    ini_list_add services "php${s}-php-fpm"
    say "$label" DONE
    result "$label" "installed"
}

set_features() {
    echo -e "\n${BOLD}Features${NC}\n"
    ini_set api "$F_API"
    ini_set mcp "$F_MCP"
    ini_set deployments "$F_DEPLOY"
    say "REST API" "$([ "$F_API" -eq 1 ] && echo DONE || echo SKIPPED)"
    say "AI assistants (MCP)" "$([ "$F_MCP" -eq 1 ] && echo DONE || echo SKIPPED)"
    say "Deployments and apps" "$([ "$F_DEPLOY" -eq 1 ] && echo DONE || echo SKIPPED)"

    if [ "$F_DNSCLUSTER" -eq 1 ]; then
        if ! systemctl is-active --quiet pdns; then
            ( cd "$WORKDIR" && bash "${RPATH}/scripts/install/install-pdns.sh" ) | tee -a "$LOG" | grep -E 'PowerDNS (Server address|API Key)'
        fi
        if systemctl is-active --quiet pdns; then
            ini_set dnscluster 1
            say "DNS cluster (PowerDNS)" DONE
            result "DNS cluster" "PowerDNS installed, dnscluster=1"
        else
            say "DNS cluster (PowerDNS)" ERROR "PowerDNS did not start, see ${WORKDIR}/install_reqad.log"
            result "DNS cluster" "FAILED: PowerDNS did not start"
        fi
    fi
}

summary() {
    local host
    host=$(hostname -f 2>/dev/null || hostname)
    echo -e "\n${BOLD}Summary${NC}\n"
    local r
    for r in "${RESULTS[@]}"; do echo "  $r"; done
    echo
    echo "  Panel:          https://${host}:2087/"
    rpm -q --quiet reqad-account && echo "  Account panel:  https://${host}:2083/"
    echo "  Switched on in server-software.ini, still off until turned on in the panel:"
    [ "$F_API" -eq 1 ]    && echo "    REST API             Settings > API access"
    [ "$F_MCP" -eq 1 ]    && echo "    AI assistants        Settings > AI access"
    [ "$F_DEPLOY" -eq 1 ] && echo "    Deployments, apps    Accounts > Deployments"
    echo -e "  ${GREY}log: ${LOG}${NC}\n"
}

print_plan() {
    printf "  EL%s · repository %s%s\n\n" "$EL" "$REPO" "$([ "$USE_TEST" -eq 1 ] && echo ' (test)')"
    if [ "$ADDONS_ONLY" -eq 1 ]; then
        printf "  base system   kept (Reqad is installed)%s\n" \
            "$([ "$TEMPLATE" == "nginx_apache_php-fpm" ] && echo ' · switch to Apache behind nginx')"
    else
        printf "  base system   template %s · php %s · email %s · ssh %s · %s%s\n" "$TEMPLATE" "$PHP_VERSION" \
            "$([ "$WITH_EMAIL" -eq 1 ] && echo yes || echo no)" "$SSH_PORT" "$TIMEZONE" "$([ "$SKIP_UPDATE" -eq 1 ] && echo ' · skip update')"
        [ -n "$SSH_KEY_IN" ]  && printf "                ssh key %s\n" "${SSH_KEY_IN:0:40}..."
        [ -n "$ADMIN_EMAIL" ] && printf "                admin email %s\n" "$ADMIN_EMAIL"
    fi
    printf "  php extra     %s\n" "${PHP_EXTRA:-none}"
    printf "  remi          %s\n" "$([ "$REMI_LOCAL" -eq 1 ] && echo "local (${REMI_LOCAL_URL}/)" || echo "public mirrors")"
    local list=''
    [ "$A_HOSTING" -eq 1 ]   && list+=" hosting-pro"
    [ "$A_ACCOUNT" -eq 1 ]   && list+=" account-panel"
    [ "$A_MODSEC" -eq 1 ]    && list+=" modsecurity"
    [ "$A_FTP" -eq 1 ]       && list+=" pureftpd"
    [ "$A_CLAMAV" -eq 1 ]    && list+=" clamav(scan:${CLAMAV_SCAN})"
    [ "$A_WIREGUARD" -eq 1 ] && list+=" wireguard"
    [ "$A_MINECRAFT" -eq 1 ] && list+=" minecraft"
    printf "  add-ons      %s\n" "${list:- none}"
    printf "  features      api=%s mcp=%s deployments=%s dnscluster=%s\n\n" "$F_API" "$F_MCP" "$F_DEPLOY" "$F_DNSCLUSTER"
}

#=[ main ]=============================================================================================================

main() {
    parse_args "$@"
    detect_platform

    [ -n "$TIMEZONE" ] || TIMEZONE=$(current_timezone)

    # Before the form: no point filling it in when the repository says no
    if [ "$DRY_RUN" -eq 0 ]; then
        [ "$(id -u)" -eq 0 ] || die "the Reqad installer must run as root"
        check_premium_repo
    fi

    # No terminal (piped with no tty, cron, packer): take the options as given
    if [ "$INTERACTIVE" -eq 1 ] && ! has_tty; then
        INTERACTIVE=0
    fi

    if [ "$INTERACTIVE" -eq 1 ]; then
        gather_facts
        reqad_version
        form_init
        if ! run_form; then
            [ -n "$FORM_FAIL" ] && die "$FORM_FAIL"
            printf "\nCancelled, nothing was changed.\n"
            exit 0
        fi
    fi

    if [ "$DRY_RUN" -eq 1 ]; then
        printf "\n  ${BOLD}Dry run - nothing will be changed.${NC}\n\n"
        print_plan
        exit 0
    fi

    # the copy answers the same allowed addresses as the premium repository
    if [ "$REMI_LOCAL" -eq 1 ] && ! remi_is_local; then
        check_repo_code "Remi copy" "$(http_code "${REMI_LOCAL_URL}/${EL}/remi/x86_64/repodata/repomd.xml")"
    fi

    mkdir -p "$WORKDIR" || die "cannot create ${WORKDIR}"
    cd "$WORKDIR" || die "cannot enter ${WORKDIR}"

    echo -e "${YELLOW}Reqad Premium installer ${VERSION}${NC}\n"
    print_plan

    if [ "$ADDONS_ONLY" -eq 1 ]; then
        reqad_running || die "--addons-only: Reqad is not installed or not running here"
        [ "$REMI_LOCAL" -eq 1 ] && remi_local
        local cur
        cur=$(ini_get template)
        if [ "$TEMPLATE_GIVEN" -eq 1 ] && ! template_reachable "$cur" "$TEMPLATE"; then
            die "this server runs ${cur}; the installer can only move nginx_php-fpm to nginx_apache_php-fpm"
        fi
    else
        # before install-el.sh asks for remi-release, so it finds it set up
        [ "$REMI_LOCAL" -eq 1 ] && remi_local
        run_base
    fi

    echo -e "\n${BOLD}Premium${NC}\n"
    enable_premium_repo
    install_addons

    if [ -n "$PHP_EXTRA" ]; then
        echo -e "\n${BOLD}More PHP versions${NC}\n"
        local v
        for v in $PHP_EXTRA; do
            [ "$v" == "$(ini_get php)" ] && continue
            php_extra_install "$v"
        done
    fi

    root_prompt
    set_features

    # the panel reads the add-ons and the switches when its PHP starts
    systemctl try-restart reqad-php-fpm >> "$LOG" 2>&1
    summary
}

main "$@"
