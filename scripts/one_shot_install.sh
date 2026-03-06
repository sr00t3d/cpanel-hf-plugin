#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Installer Wrapper v1.0.0                                ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Call Install High Forensic on cPanel                           ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
HF_PLUGIN_ONE_SHOT_INSTALL_VERSION="2026-03-06-r9"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"

usage() {
    cat <<'USAGE'
Usage:
  one_shot_install.sh --version
  one_shot_install.sh --package /path/plugin.tar.gz [--theme jupiter] [--hf-url URL] [--hf-sha256 SHA256] [--global-hf /usr/local/bin/hf.sh] [--no-global-hf] [--no-restart]
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
HF_SOURCE_URL=""
HF_SOURCE_SHA256=""
GLOBAL_HF_TARGET="/usr/local/bin/hf.sh"
SYNC_GLOBAL_HF=1
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
        --hf-url)
            [[ -n "${2:-}" ]] || { echo "ERROR: --hf-url requires a value."; exit 1; }
            HF_SOURCE_URL="$2"
            shift 2
            ;;
        --hf-sha256)
            [[ -n "${2:-}" ]] || { echo "ERROR: --hf-sha256 requires a value."; exit 1; }
            HF_SOURCE_SHA256="$(printf '%s' "$2" | tr 'A-F' 'a-f')"
            shift 2
            ;;
        --global-hf)
            [[ -n "${2:-}" ]] || { echo "ERROR: --global-hf requires a value."; exit 1; }
            GLOBAL_HF_TARGET="$2"
            shift 2
            ;;
        --no-global-hf)
            SYNC_GLOBAL_HF=0
            shift
            ;;
        --no-restart)
            RESTART=0
            shift
            ;;
        -V|--version)
            echo "$HF_PLUGIN_ONE_SHOT_INSTALL_VERSION"
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

WORKDIR="$(mktemp -d /tmp/hforensic-install.XXXXXX)"
cleanup() {
    rm -rf -- "$WORKDIR"
}
trap cleanup EXIT

echo "Extracting package to: $WORKDIR"
validate_tarball_entries "$PACKAGE"
tar --no-same-owner --no-same-permissions -xzf "$PACKAGE" -C "$WORKDIR"

INSTALL_SCRIPT="$WORKDIR/scripts/install.sh"
if [[ ! -f "$INSTALL_SCRIPT" ]]; then
    echo "ERROR: scripts/install.sh not found in package."
    exit 1
fi

CMD=("bash" "$INSTALL_SCRIPT" "--theme" "$THEME" "--global-hf" "$GLOBAL_HF_TARGET")
if [[ -n "$HF_SOURCE_URL" ]]; then
    CMD+=("--hf-url" "$HF_SOURCE_URL")
fi
if [[ -n "$HF_SOURCE_SHA256" ]]; then
    CMD+=("--hf-sha256" "$HF_SOURCE_SHA256")
fi
if [[ "$SYNC_GLOBAL_HF" -eq 0 ]]; then
    CMD+=("--no-global-hf")
fi

echo "Running installer: ${CMD[*]}"
"${CMD[@]}"

if [[ "$RESTART" -eq 1 && -x /usr/local/cpanel/scripts/restartsrv_cpsrvd ]]; then
    echo "Restarting cpsrvd (graceful)..."
    /usr/local/cpanel/scripts/restartsrv_cpsrvd --graceful
fi

echo "Install OK."
echo "Open cPanel > Files > High Forensic"
