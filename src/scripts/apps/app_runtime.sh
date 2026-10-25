#!/bin/bash
# Language runtimes for Node.js / Python apps (Accounts > Deployments, `reqad app`).
#
#   app_runtime.sh list                     one line per runtime: <kind> <version> <full version> <bin dir>
#                                           (also links python3.x that came from elsewhere)
#   app_runtime.sh available node           the newest Node.js major and the LTS ones (from nodejs.org)
#   app_runtime.sh install node <major>     official Linux build into /opt/reqad/node/<major>
#   app_runtime.sh install python <3.x>     python3.x from the OS repositories (AppStream)
#   app_runtime.sh update node <major>      the latest release of that major (same as install)
#   app_runtime.sh remove node <major>
#
# Node.js: the tarball of the latest v<major>.x release is downloaded over
# HTTPS from nodejs.org and checked against SHASUMS256.txt of that release
# before anything is replaced; the new tree is moved into place in one step,
# so running apps keep their files until they restart. Python: dnf installs
# python3.x (+ pip); /opt/reqad/python/<v>/bin gets python/python3 links so an
# app's PATH can point at one version.
#
# Run as root; the panel calls it through sudo. Apps using a runtime are
# restarted by the caller, not here.

set -o pipefail
[ "$(id -u)" -eq 0 ] || { echo "This script must be run as root"; exit 1; }

NODE_DIR=/opt/reqad/node
PY_DIR=/opt/reqad/python
DIST=https://nodejs.org/dist

die() { echo "error: $*" >&2; exit 1; }

node_arch() {
    case "$(uname -m)" in
        x86_64)  echo x64 ;;
        aarch64) echo arm64 ;;
        *) die "no Node.js build for $(uname -m)" ;;
    esac
}

list() {
    local d v
    for d in "$NODE_DIR"/*/; do
        [ -x "$d/bin/node" ] || continue
        v=$("$d/bin/node" -v 2>/dev/null) || continue
        echo "node $(basename "$d") ${v#v} ${d%/}/bin"
    done
    for v in 3.9 3.11 3.12 3.13; do
        [ -x "/usr/bin/python$v" ] || continue
        # python3.x installed some other way: give it the PATH links too
        [ -L "$PY_DIR/$v/bin/python" ] || { mkdir -p "$PY_DIR/$v/bin" && ln -sfn "/usr/bin/python$v" "$PY_DIR/$v/bin/python" && ln -sfn "/usr/bin/python$v" "$PY_DIR/$v/bin/python3"; }
        echo "python $v $(/usr/bin/python$v -c 'import platform; print(platform.python_version())' 2>/dev/null) $PY_DIR/$v/bin"
    done
}

install_node() {
    local major=$1 arch tmp sums file ver
    [[ "$major" =~ ^[0-9]{2}$ ]] || die "Node.js major version must be a number like 22 or 24"
    arch=$(node_arch)
    tmp=$(mktemp -d /tmp/reqad-node.XXXXXX) || die "no temp dir"
    trap 'rm -rf "$tmp"' EXIT
    sums=$(curl -fsSL --max-time 30 "$DIST/latest-v$major.x/SHASUMS256.txt") || die "nodejs.org has no release line v$major.x"
    file=$(printf '%s\n' "$sums" | awk -v a="linux-$arch.tar.xz" '$2 ~ a"$" {print $2; exit}')
    [ -n "$file" ] || die "no linux-$arch build in v$major.x"
    ver=${file#node-v}; ver=${ver%%-linux*}
    if [ -x "$NODE_DIR/$major/bin/node" ] && [ "$("$NODE_DIR/$major/bin/node" -v)" = "v$ver" ]; then
        echo "node $major: v$ver is already installed"
        return 0
    fi
    echo "node $major: downloading v$ver ($arch)..."
    curl -fsSL --max-time 600 -o "$tmp/$file" "$DIST/latest-v$major.x/$file" || die "download failed"
    (cd "$tmp" && printf '%s\n' "$sums" | grep -F "  $file" | sha256sum -c --quiet -) || die "checksum mismatch for $file - not installed"
    mkdir -p "$NODE_DIR"
    rm -rf "$NODE_DIR/.$major.new"
    mkdir "$NODE_DIR/.$major.new"
    tar -xJf "$tmp/$file" -C "$NODE_DIR/.$major.new" --strip-components=1 --no-same-owner || die "could not unpack $file"
    chown -R root:root "$NODE_DIR/.$major.new"
    chmod -R a+rX,go-w "$NODE_DIR/.$major.new"
    "$NODE_DIR/.$major.new/bin/node" -v >/dev/null || die "the new node does not run on this system (glibc too old?)"
    # swap in one step; the old tree goes after the rename
    if [ -e "$NODE_DIR/$major" ]; then
        mv -T "$NODE_DIR/$major" "$NODE_DIR/.$major.old"
    fi
    mv -T "$NODE_DIR/.$major.new" "$NODE_DIR/$major"
    rm -rf "$NODE_DIR/.$major.old"
    echo "node $major: v$ver installed in $NODE_DIR/$major"
}

install_python() {
    local v=$1
    [[ "$v" =~ ^3\.(9|1[0-9])$ ]] || die "Python version must look like 3.12"
    if [ ! -x "/usr/bin/python$v" ]; then
        echo "python $v: installing python$v from the OS repositories..."
        dnf -y -q install "python$v" "python$v-pip" >/dev/null || die "dnf could not install python$v"
    fi
    [ -x "/usr/bin/python$v" ] || die "python$v is not available"
    mkdir -p "$PY_DIR/$v/bin"
    ln -sfn "/usr/bin/python$v" "$PY_DIR/$v/bin/python"
    ln -sfn "/usr/bin/python$v" "$PY_DIR/$v/bin/python3"
    echo "python $v: $(/usr/bin/python$v -V 2>&1) ready"
}

case "$1" in
    list) list ;;
    available)
        [ "$2" = node ] || die "usage: $0 available node"
        curl -fsSL --max-time 30 "$DIST/index.json" | python3 -c '
import json, sys, datetime
seen = {}
for r in json.load(sys.stdin):
    m = int(r["version"][1:].split(".")[0])
    if m not in seen:
        seen[m] = (r["version"], r["lts"] or "")
# the newest line, and the LTS lines (odd majors never become LTS and go
# out of support within months)
majors = sorted(seen, reverse=True)
show = [majors[0]] + [m for m in majors[1:] if seen[m][1]][:2]   # the two newest LTS: older ones are end of life
for m in show:
    print(m, seen[m][0][1:], seen[m][1])
' ;;
    install|update)
        case "$2" in
            node)   install_node "$3" ;;
            python) install_python "$3" ;;
            *) die "usage: $0 install node <major> | install python <3.x>" ;;
        esac ;;
    remove)
        [ "$2" = node ] && [[ "$3" =~ ^[0-9]{2}$ ]] || die "usage: $0 remove node <major> (Python stays an OS package)"
        [ -d "$NODE_DIR/$3" ] || die "node $3 is not installed"
        rm -rf "$NODE_DIR/$3"
        echo "node $3: removed" ;;
    *) sed -n '2,10p' "$0"; exit 2 ;;
esac
