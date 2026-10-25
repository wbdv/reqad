#!/bin/bash
# CPU, memory and process limits for a hosting account's SSH, cron and
# web-terminal sessions.
#
# Every login, cron job and terminal of an account runs in the systemd slice
# user-<uid>.slice (pam_systemd). A drop-in for that slice caps the whole group:
#
#   /etc/systemd/system/user-<uid>.slice.d/50-reqad-limits.conf
#
#   CPU        100% = one core; a busy job slows down, it is not killed
#   Memory     the kernel kills the largest process of THAT account at the cap
#   Processes  new processes are refused at the cap (fork fails)
#
# The drop-in is the state - its first line holds the mode: default (follows
# the server defaults below), custom, or none (no limit). Root, the admin and
# system users get no drop-in and are never limited. Website PHP is not touched
# (php-fpm pools run in php-fpm.service, limited by the pool settings).
#
# Without swap the memory cap kills; with swap on cgroup v1 (EL8) the kernel
# would rather swap the session out. user.slice gets swappiness 0 for that
# (inherited by every new session; reapplied at boot by
# reqad-session-limits.service). On cgroup v2 the drop-in sets MemorySwapMax=0.
#
# Hits are recorded: rsyslog copies the kernel's notes ("Memory cgroup out of
# memory: Killed process ... UID:<uid>", "fork rejected by pids controller in
# /user.slice/user-<uid>.slice") to /var/log/reqad/session-limits.log.
#
# Usage:
#   session_limits.sh show   <user>                 limits, usage now, hits (key=value)
#   session_limits.sh set    <user> default|none
#   session_limits.sh set    <user> <cpu%> <memory MB> <processes>
#   session_limits.sh remove <user|uid>             account deleted
#   session_limits.sh events [<user>] [<count>]     recent hits, newest last (tab separated)
#   session_limits.sh setup  [--dry-run|--apply|--remove]
#                            server pieces + default limits for every account
#                            without a drop-in (and refresh of 'default' ones)
#   session_limits.sh boot                          swappiness for user.slice (the unit)

if [ "$(id -u)" -ne 0 ]; then
    echo "This script must be run as root"
    exit 1
fi

DEF_CPU=100
DEF_TASKS=512
DEF_MEM_CAP=2048                       # MB; less on small servers (25% of RAM)
LOG=/var/log/reqad/session-limits.log
RSYSLOG_CONF=/etc/rsyslog.d/reqad-session-limits.conf
LOGROTATE_CONF=/etc/logrotate.d/reqad-session-limits
UNIT=/etc/systemd/system/reqad-session-limits.service
DB=/usr/local/reqad/db/reqad.db
SELF=/usr/local/reqad/scripts/session_limits.sh

cgroup2() { [ "$(stat -fc %T /sys/fs/cgroup 2>/dev/null)" = cgroup2fs ]; }

def_mem() {
    local total
    # whole GB (MemTotal is a little under the RAM size), a quarter of it
    total=$(awk '/^MemTotal:/ {print int(($2 / 1024 + 512) / 1024) * 256}' /proc/meminfo)
    [ "$total" -gt 0 ] && [ "$total" -lt "$DEF_MEM_CAP" ] && echo "$total" || echo "$DEF_MEM_CAP"
}

dropin()  { echo "/etc/systemd/system/user-$1.slice.d/50-reqad-limits.conf"; }

# uid of a hosting account; empty for unknown users, root and system users
acct_uid() {
    local uid
    uid=$(id -u "$1" 2>/dev/null) || return 1
    [ "$uid" -ge 1000 ] || return 1
    echo "$uid"
}

# mode cpu mem tasks of a drop-in ("missing" when there is none)
read_dropin() {
    local f
    f=$(dropin "$1")
    if [ ! -f "$f" ]; then echo "missing - - -"; return; fi
    local mode cpu mem tasks
    mode=$(sed -n '1s/.*mode: *\([a-z]*\).*/\1/p' "$f")
    cpu=$(sed -n 's/^CPUQuota=\([0-9]*\)%.*/\1/p' "$f" | head -1)
    mem=$(sed -n 's/^MemoryLimit=\([0-9]*\)M.*/\1/p' "$f" | head -1)
    tasks=$(sed -n 's/^TasksMax=\([0-9]*\)$/\1/p' "$f" | head -1)
    echo "${mode:-custom} ${cpu:--} ${mem:--} ${tasks:--}"
}

# write_dropin <uid> <user> <mode> [cpu mem tasks]; prints 1 when the file changed
write_dropin() {
    local uid=$1 user=$2 mode=$3 cpu=$4 mem=$5 tasks=$6 f new
    f=$(dropin "$uid")
    if [ "$mode" = none ]; then
        new="# Managed by Reqad (scripts/session_limits.sh) - mode: none
# $user: no limits for SSH, cron and web-terminal sessions.
[Slice]
CPUQuota=
MemoryLimit=infinity
MemoryMax=infinity
MemorySwapMax=infinity
TasksMax=infinity"
    else
        new="# Managed by Reqad (scripts/session_limits.sh) - mode: $mode
# Limits for $user's SSH, cron and web-terminal sessions, all together.
# Change them in Accounts > Manage > Advanced Config > Session limits or with
# 'reqad account limits $user'; edits made here are kept unless mode is default.
[Slice]
CPUQuota=${cpu}%
MemoryLimit=${mem}M
MemoryMax=${mem}M
MemorySwapMax=0
TasksMax=${tasks}"
    fi
    if [ -f "$f" ] && [ "$(cat "$f")" = "$new" ]; then
        echo 0; return
    fi
    mkdir -p "$(dirname "$f")"
    printf '%s\n' "$new" > "$f"
    chmod 0644 "$f"
    echo 1
}

# cgroup v1 keeps a session swappable unless its memcg says otherwise; new
# cgroups inherit the value from user.slice at creation, running ones need it set
set_swappiness() {
    cgroup2 && return 0
    local d=/sys/fs/cgroup/memory/user.slice
    [ -d "$d" ] || return 0
    find "$d" -name memory.swappiness 2>/dev/null | while read -r f; do
        echo 0 > "$f" 2>/dev/null
    done
}

# key=value lines for show
usage_now() {
    local uid=$1 base cur peak tasks thr
    if cgroup2; then
        base=/sys/fs/cgroup/user.slice/user-$uid.slice
        [ -d "$base" ] || { echo "active=0"; return; }
        cur=$(cat "$base/memory.current" 2>/dev/null)
        peak=$(cat "$base/memory.peak" 2>/dev/null)
        tasks=$(cat "$base/pids.current" 2>/dev/null)
        thr=$(awk '$1 == "throttled_usec" {printf "%d", $2 / 1000000}' "$base/cpu.stat" 2>/dev/null)
    else
        base=/sys/fs/cgroup/memory/user.slice/user-$uid.slice
        [ -d "$base" ] || { echo "active=0"; return; }
        # rss + cache like the cap sees it, without the reclaimable page cache
        cur=$(awk '$1 == "total_rss" {r=$2} $1 == "total_shmem" {s=$2} END {printf "%d", r + s}' "$base/memory.stat" 2>/dev/null)
        peak=$(cat "$base/memory.max_usage_in_bytes" 2>/dev/null)
        tasks=$(cat "/sys/fs/cgroup/pids/user.slice/user-$uid.slice/pids.current" 2>/dev/null)
        thr=$(awk '$1 == "throttled_time" {printf "%d", $2 / 1000000000}' \
              "/sys/fs/cgroup/cpu,cpuacct/user.slice/user-$uid.slice/cpu.stat" 2>/dev/null)
    fi
    echo "active=1"
    echo "mem_now_mb=$(( ${cur:-0} / 1048576 ))"
    [ -n "$peak" ] && echo "mem_peak_mb=$(( peak / 1048576 ))"
    echo "tasks_now=${tasks:-0}"
    echo "cpu_throttled_s=${thr:-0}"
}

# events: time<TAB>user<TAB>memory|processes<TAB>detail
events() {
    local want_uid=$1 count=$2
    [ -r "$LOG" ] || return 0
    awk -v want="$want_uid" '
        function when(t) { sub(/T/, " ", t); return substr(t, 1, 19) }
        function uname(u,   cmd, n) {
            if (!(u in names)) { cmd = "id -nu " u " 2>/dev/null"; n = ""; cmd | getline n; close(cmd); names[u] = (n == "" ? "uid " u : n) }
            return names[u]
        }
        /Memory cgroup out of memory: Killed process/ {
            if (!match($0, /UID:[0-9]+/)) next
            u = substr($0, RSTART + 4, RLENGTH - 4)
            if (want != "" && u != want) next
            p = ""; if (match($0, /Killed process [0-9]+ \([^)]*\)/)) {
                split(substr($0, RSTART + 15, RLENGTH - 16), a, " [(]"); p = a[2] " (pid " a[1] ")"
            }
            rss = ""; if (match($0, /anon-rss:[0-9]+kB/)) { rss = int(substr($0, RSTART + 9, RLENGTH - 11) / 1024) " MB" }
            print when($1) "\t" uname(u) "\tmemory\tkilled " p (rss != "" ? ", using " rss : "")
            next
        }
        /fork rejected by pids controller in \/user.slice\/user-/ {
            if (!match($0, /user-[0-9]+\.slice/)) next
            u = substr($0, RSTART + 5, RLENGTH - 11)
            if (want != "" && u != want) next
            print when($1) "\t" uname(u) "\tprocesses\tnew process refused (process limit reached)"
        }
    ' "$LOG" | tail -n "${count:-20}"
}

case "$1" in
show)
    uid=$(acct_uid "$2") || { echo "error=not a hosting account: $2"; exit 1; }
    read -r mode cpu mem tasks <<< "$(read_dropin "$uid")"
    echo "user=$2"
    echo "uid=$uid"
    echo "mode=$mode"
    echo "cpu=$cpu"
    echo "memory_mb=$mem"
    echo "tasks=$tasks"
    echo "default_cpu=$DEF_CPU"
    echo "default_memory_mb=$(def_mem)"
    echo "default_tasks=$DEF_TASKS"
    usage_now "$uid"
    since=$(date -d '-30 days' +%Y-%m-%d)
    hits=$(events "$uid" 100000 | awk -F'\t' -v s="$since" 'substr($1, 1, 10) >= s')
    echo "hits_memory_30d=$(printf '%s\n' "$hits" | grep -c $'\tmemory\t')"
    echo "hits_tasks_30d=$(printf '%s\n' "$hits" | grep -c $'\tprocesses\t')"
    ;;

set)
    user=$2
    uid=$(acct_uid "$user") || { echo "not a hosting account: $user"; exit 1; }
    case "$3" in
        default) mode=default; cpu=$DEF_CPU; mem=$(def_mem); tasks=$DEF_TASKS ;;
        none)    mode=none ;;
        *)       mode=custom; cpu=$3; mem=$4; tasks=$5
                 for v in "$cpu" "$mem" "$tasks"; do
                     [[ "$v" =~ ^[0-9]+$ ]] || { echo "usage: $0 set <user> <cpu%> <memory MB> <processes>"; exit 2; }
                 done
                 cores=$(nproc)
                 [ "$cpu" -ge 5 ] && [ "$cpu" -le $((cores * 100)) ] || { echo "CPU must be 5-$((cores * 100))% (100% = one core, this server has $cores)"; exit 2; }
                 [ "$mem" -ge 64 ] || { echo "memory must be at least 64 MB"; exit 2; }
                 [ "$tasks" -ge 16 ] && [ "$tasks" -le 100000 ] || { echo "processes must be 16-100000"; exit 2; }
                 ;;
    esac
    changed=$(write_dropin "$uid" "$user" "$mode" "$cpu" "$mem" "$tasks")
    # applies at once, also to sessions already running
    [ "$changed" = 1 ] && systemctl daemon-reload
    set_swappiness
    if [ "$mode" = none ]; then
        echo "$user: no session limits"
    else
        echo "$user: session limits CPU ${cpu}%, memory ${mem} MB, ${tasks} processes ($mode)"
    fi
    ;;

remove)
    uid=$2
    [[ "$uid" =~ ^[0-9]+$ ]] || uid=$(id -u "$2" 2>/dev/null)
    [ -n "$uid" ] && [ "$uid" -ge 1000 ] || exit 0
    d=/etc/systemd/system/user-$uid.slice.d
    if [ -f "$d/50-reqad-limits.conf" ]; then
        rm -f "$d/50-reqad-limits.conf"
        rmdir "$d" 2>/dev/null
        systemctl daemon-reload
    fi
    ;;

events)
    uid=""
    if [ -n "$2" ] && ! [[ "$2" =~ ^[0-9]+$ ]]; then
        uid=$(id -u "$2" 2>/dev/null) || { echo "unknown user: $2"; exit 1; }
        shift
    fi
    events "$uid" "${2:-20}"
    ;;

boot)
    set_swappiness
    ;;

setup)
    MODE=dry
    case "$2" in
        ''|--dry-run) MODE=dry ;;
        --apply)      MODE=apply ;;
        --remove)     MODE=remove ;;
        *) echo "usage: $0 setup [--dry-run|--apply|--remove]"; exit 2 ;;
    esac

    if [ "$MODE" = remove ]; then
        for f in /etc/systemd/system/user-*.slice.d/50-reqad-limits.conf; do
            [ -f "$f" ] || continue
            rm -f "$f"; rmdir "$(dirname "$f")" 2>/dev/null
        done
        systemctl disable --now reqad-session-limits.service >/dev/null 2>&1
        rm -f "$UNIT" "$RSYSLOG_CONF" "$LOGROTATE_CONF"
        systemctl daemon-reload
        systemctl restart rsyslog 2>/dev/null
        cgroup2 || echo 60 > /sys/fs/cgroup/memory/user.slice/memory.swappiness 2>/dev/null
        echo "session limits removed (the hit log $LOG is kept)"
        exit 0
    fi

    [ "$MODE" = dry ] && echo "Session limits (dry run): CPU ${DEF_CPU}%, memory $(def_mem) MB, ${DEF_TASKS} processes per account"

    # 1. server pieces
    rs='# Managed by Reqad (scripts/session_limits.sh): the kernel'"'"'s notes when a
# hosting account'"'"'s sessions hit their memory or process limit
if $programname == "kernel" and ($msg contains "Memory cgroup out of memory: Killed process" or $msg contains "fork rejected by pids controller in /user.slice/") then {
    action(type="omfile" file="'"$LOG"'" template="RSYSLOG_FileFormat" fileCreateMode="0640")
}'
    lr="# Managed by Reqad (scripts/session_limits.sh)
$LOG {
    monthly
    rotate 12
    compress
    delaycompress
    missingok
    notifempty
    postrotate
        /usr/bin/systemctl kill -s HUP rsyslog.service >/dev/null 2>&1 || true
    endscript
}"
    un="# Managed by Reqad (scripts/session_limits.sh)
[Unit]
Description=Reqad: account sessions are not swapped out, so memory limits apply
After=systemd-logind.service

[Service]
Type=oneshot
RemainAfterExit=yes
ExecStart=$SELF boot

[Install]
WantedBy=multi-user.target"
    if [ "$MODE" = dry ]; then
        [ "$(cat "$RSYSLOG_CONF" 2>/dev/null)" = "$rs" ] || echo "  would write $RSYSLOG_CONF (hit log $LOG)"
        [ "$(cat "$LOGROTATE_CONF" 2>/dev/null)" = "$lr" ] || echo "  would write $LOGROTATE_CONF"
        cgroup2 || [ "$(cat "$UNIT" 2>/dev/null)" = "$un" ] || echo "  would install reqad-session-limits.service (swappiness 0 for user.slice)"
    else
        mkdir -p /var/log/reqad
        if [ ! -f "$LOG" ]; then
            # hits since boot that are still in the kernel journal
            install -m 0640 /dev/null "$LOG"
            journalctl -k -b -o short-iso --no-pager 2>/dev/null \
                | grep -E 'Memory cgroup out of memory: Killed process|fork rejected by pids controller in /user.slice/' >> "$LOG"
        fi
        if [ "$(cat "$RSYSLOG_CONF" 2>/dev/null)" != "$rs" ]; then
            printf '%s\n' "$rs" > "$RSYSLOG_CONF"
            if rsyslogd -N1 >/dev/null 2>&1; then
                systemctl restart rsyslog && echo "  rsyslog: limit hits go to $LOG"
            else
                rm -f "$RSYSLOG_CONF"
                echo "  rsyslog: config test failed - hit log not set up"
            fi
        fi
        [ "$(cat "$LOGROTATE_CONF" 2>/dev/null)" = "$lr" ] || printf '%s\n' "$lr" > "$LOGROTATE_CONF"
        if ! cgroup2 && [ "$(cat "$UNIT" 2>/dev/null)" != "$un" ]; then
            printf '%s\n' "$un" > "$UNIT"
            systemctl daemon-reload
            systemctl enable --now reqad-session-limits.service >/dev/null 2>&1
            echo "  reqad-session-limits.service installed (swappiness 0 for user.slice)"
        fi
        set_swappiness
    fi

    # 2. every hosting account: default limits unless it has its own
    n=0; kept=0
    users=$(/usr/bin/sqlite3 -init /dev/null -batch -noheader -list "$DB" 'SELECT user FROM accounts ORDER BY user' 2>/dev/null)
    for u in $users; do
        uid=$(acct_uid "$u") || continue
        read -r mode _ _ _ <<< "$(read_dropin "$uid")"
        if [ "$mode" = custom ] || [ "$mode" = none ]; then
            kept=$((kept + 1)); continue
        fi
        if [ "$MODE" = dry ]; then
            [ "$mode" = missing ] && { echo "  $u: would get the default limits"; n=$((n + 1)); }
            continue
        fi
        c=$(write_dropin "$uid" "$u" default "$DEF_CPU" "$(def_mem)" "$DEF_TASKS")
        n=$((n + c))
    done
    if [ "$MODE" = dry ]; then
        echo "Would set $n account(s); $kept with their own limits left alone. Run with --apply."
    else
        [ "$n" -gt 0 ] && systemctl daemon-reload
        echo "session limits: $n account(s) set to the defaults, $kept with their own limits left alone"
    fi
    ;;

*)
    sed -n '2,/^$/s/^# \{0,1\}//p' "$0" | sed -n '/^Usage:/,$p'
    exit 2
    ;;
esac
