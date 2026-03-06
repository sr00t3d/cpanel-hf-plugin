#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Uninstaller v1.0.0                                      ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Uninstall High Forensic on cPanel                              ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
HF_PLUGIN_ONE_SHOT_UNINSTALL_VERSION="2026-03-06-r7"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"

usage() {
    cat <<'USAGE'
Usage:
  one_shot_uninstall.sh --version
  one_shot_uninstall.sh --package /path/plugin.tar.gz [--theme jupiter] [--no-restart]
USAGE
}

validate_tarball_entries() {
    local package_path="$1"
    local bad=0

    while IFS= read -r entry; do
        [ -z "$entry" ] && continue
        case "$entry" in
            /*)
                echo "ERROR: tarball contains absolute path entry: $entry"
                bad=1
                ;;
        esac
        if [[ "$entry" =~ (^|/)\.\.(/|$) ]]; then
            echo "ERROR: tarball contains path traversal entry: $entry"
            bad=1
        fi
    done < <(tar -tzf "$package_path")

    return "$bad"
}

PACKAGE=""
THEME="jupiter"
RESTART=1

while [[ $# -gt 0 ]]; do
    case "$1" in
        --package)
            [[ -n "${2:-}" ]] || { echo "ERROR: --package requires a value."; exit 1; }
            PACKAGE="$2"
            shift 2
            ;;
        --theme)
            [[ -n "${2:-}" ]] || { echo "ERROR: --theme requires a value."; exit 1; }
            THEME="$2"
            shift 2
            ;;
        --no-restart)
            RESTART=0
            shift
            ;;
        -V|--version)
            echo "$HF_PLUGIN_ONE_SHOT_UNINSTALL_VERSION"
            exit 0
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "ERROR: unknown option: $1"
            usage
            exit 1
            ;;
    esac
done

if [[ -z "$PACKAGE" ]]; then
    echo "ERROR: --package is required."
    usage
    exit 1
fi

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: run as root."
    exit 1
fi

if [[ ! -f "$PACKAGE" ]]; then
    echo "ERROR: package not found: $PACKAGE"
    exit 1
fi

WORKDIR="$(mktemp -d /tmp/hforensic-uninstall.XXXXXX)"
cleanup() {
    rm -rf -- "$WORKDIR"
}
trap cleanup EXIT

echo "Extracting package to: $WORKDIR"
validate_tarball_entries "$PACKAGE"
tar --no-same-owner --no-same-permissions -xzf "$PACKAGE" -C "$WORKDIR"

UNINSTALL_SCRIPT="$WORKDIR/scripts/uninstall.sh"
if [[ ! -f "$UNINSTALL_SCRIPT" ]]; then
    echo "ERROR: scripts/uninstall.sh not found in package."
    exit 1
fi

echo "Running uninstall script: $UNINSTALL_SCRIPT --theme $THEME"
bash "$UNINSTALL_SCRIPT" --theme "$THEME"

if [[ "$RESTART" -eq 1 && -x /usr/local/cpanel/scripts/restartsrv_cpsrvd ]]; then
    echo "Restarting cpsrvd (graceful)..."
    /usr/local/cpanel/scripts/restartsrv_cpsrvd --graceful
fi

echo "Uninstall OK."