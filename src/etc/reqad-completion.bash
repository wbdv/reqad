# bash completion for reqad — the alternate hosting control panel.
#
# Installed by the RPM as /usr/share/bash-completion/completions/reqad, which
# bash-completion loads the first time someone types "reqad" and presses Tab.
# Nothing has to be sourced from a profile.
#
# The candidates come from `reqad __complete`, so the commands, options and
# values stay in one place (cli_spec() in bin/reqad) instead of being repeated
# here and drifting apart. Values that need root (databases, mailboxes) are only
# offered to a root shell; accounts and mail domains come out of the panel db
# and complete for anyone.

_reqad() {
    local cur prev words cword
    if declare -F _init_completion >/dev/null 2>&1; then
        # -s splits --opt=value, so completing "--php=8<Tab>" works
        _init_completion -s || return
    else
        COMPREPLY=()
        cur=${COMP_WORDS[COMP_CWORD]}
        prev=${COMP_WORDS[COMP_CWORD-1]}
        words=("${COMP_WORDS[@]}")
        cword=$COMP_CWORD
    fi

    local bin=${words[0]}
    # "sudo reqad …" hands us reqad as words[0]; fall back to the packaged path
    # when it is not something we can execute (e.g. a bare name not in PATH).
    type -P "$bin" >/dev/null 2>&1 || bin=/usr/local/reqad/bin/reqad
    [ -x "$bin" ] || command -v "$bin" >/dev/null 2>&1 || return

    local IFS=$'\n'
    COMPREPLY=($(compgen -W "$("$bin" __complete "$cword" "$cur" "$prev" "${words[@]}" 2>/dev/null)" -- "$cur"))
}

complete -F _reqad reqad /usr/local/reqad/bin/reqad
