#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Uninstaller Wrapper v1.0.0                              ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Call uninstall High Forensic on cPanel                         ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
HF_PLUGIN_UNINSTALL_VERSION="2026-03-06-r5"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"

PLUGIN_ID="hforensic"

usage() {
    cat <<'USAGE'
Usage:
  uninstall.sh --version
  uninstall.sh [--theme jupiter]
USAGE
}

THEME="jupiter"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

while [[ $# -gt 0 ]]; do
    case "$1" in
        --theme)
            [[ -n "${2:-}" ]] || { echo "ERROR: --theme requires a value."; exit 1; }
            THEME="$2"
            shift 2
            ;;
        -V|--version)
            echo "$HF_PLUGIN_UNINSTALL_VERSION"
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

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: run as root."
    exit 1
fi

UNINSTALL_PLUGIN="/usr/local/cpanel/scripts/uninstall_plugin"
if [[ ! -x "$UNINSTALL_PLUGIN" ]]; then
    echo "ERROR: cPanel uninstall_plugin script not found."
    exit 1
fi

echo "Unregistering plugin menu entry from theme: $THEME"
"$UNINSTALL_PLUGIN" "$PLUGIN_DIR" "--theme=${THEME}" || true

for dir in \
    "/usr/local/cpanel/base/frontend/${THEME}/${PLUGIN_ID}"
do
    if [[ -d "$dir" ]]; then
        echo "Removing plugin files: $dir"
        rm -rf -- "$dir"
    fi
done

HELPER_TARGET="/usr/local/bin/hf-runweblogs-safe"
if [[ -f "$HELPER_TARGET" ]]; then
    rm -f -- "$HELPER_TARGET"
fi

for sudoers_file in \
    "/etc/sudoers.d/hforensic_runweblogs"
do
    if [[ -f "$sudoers_file" ]]; then
        rm -f -- "$sudoers_file"
    fi
done

echo "Plugin removed."