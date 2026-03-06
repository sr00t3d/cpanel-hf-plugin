#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Installer v1.0.0                                        ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Install High Forensic on cPanel                                ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
HF_PLUGIN_INSTALL_VERSION="2026-03-06-r9"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"

PLUGIN_ID="hforensic"
PLUGIN_LABEL="High Forensic"
HF_SOURCE_URL_DEFAULT="https://raw.githubusercontent.com/sr00t3d/cpanel-hf/refs/heads/main/hf.sh"
HF_SOURCE_SHA256_DEFAULT="0409d6ac0e9a4865b335827f306e370e758d4b52eaa574b150fdd2ee49329a1f"

usage() {
    cat <<'USAGE'
Usage:
  install.sh --version
  install.sh [--theme jupiter] [--hf-url URL] [--hf-sha256 SHA256] [--global-hf /usr/local/bin/hf.sh] [--no-global-hf]

Options:
  --theme         cPanel frontend theme (default: jupiter)
  --hf-url        URL used to download hf.sh (default: GitHub main hf.sh)
  --hf-sha256     Expected SHA-256 for downloaded hf.sh
  --global-hf     Path for global hf.sh copy (default: /usr/local/bin/hf.sh)
  --no-global-hf  Skip global hf.sh download/copy (requires existing valid hf.sh)
USAGE
}

download_hf_script() {
    local url="$1"
    local output="$2"

    if command -v curl >/dev/null 2>&1; then
        curl -fsSL "$url" -o "$output"
        return
    fi

    if command -v wget >/dev/null 2>&1; then
        wget -qO "$output" "$url"
        return
    fi

    echo "ERROR: curl or wget is required to download hf.sh."
    exit 1
}

calc_sha256() {
    local path="$1"
    if command -v sha256sum >/dev/null 2>&1; then
        sha256sum "$path" | awk '{print $1}'
        return 0
    fi
    if command -v shasum >/dev/null 2>&1; then
        shasum -a 256 "$path" | awk '{print $1}'
        return 0
    fi
    echo "ERROR: sha256sum or shasum is required to validate hf.sh integrity."
    exit 1
}

validate_hf_script() {
    local path="$1"
    local expected_sha="${2:-}"

    if [[ ! -f "$path" ]]; then
        echo "ERROR: hf.sh not found: $path"
        exit 1
    fi

    if [[ -n "$expected_sha" ]]; then
        if [[ ! "$expected_sha" =~ ^[a-f0-9]{64}$ ]]; then
            echo "ERROR: invalid --hf-sha256 (must be 64 hex chars)."
            exit 1
        fi
        local actual_sha
        actual_sha="$(calc_sha256 "$path" | tr 'A-F' 'a-f')"
        if [[ "$actual_sha" != "$expected_sha" ]]; then
            echo "ERROR: hf.sh SHA-256 mismatch."
            echo "Expected: $expected_sha"
            echo "Actual:   $actual_sha"
            exit 1
        fi
    fi

    if ! grep -q 'HF_USER_MODE_FTP_READY=1' "$path" 2>/dev/null; then
        echo "ERROR: hf.sh is outdated (missing user-mode FTP marker)."
        exit 1
    fi

    if ! grep -q '^search_user_ftp_logs()' "$path" 2>/dev/null; then
        echo "ERROR: hf.sh is outdated (missing search_user_ftp_logs function)."
        exit 1
    fi
}

THEME="jupiter"
HF_SOURCE_URL="$HF_SOURCE_URL_DEFAULT"
HF_SOURCE_SHA256="$HF_SOURCE_SHA256_DEFAULT"
GLOBAL_HF_TARGET="/usr/local/bin/hf.sh"
SYNC_GLOBAL_HF=1
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(cd "${SCRIPT_DIR}/.." && pwd)"

while [[ $# -gt 0 ]]; do
    case "$1" in
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
        -V|--version)
            echo "$HF_PLUGIN_INSTALL_VERSION"
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

if [[ "$GLOBAL_HF_TARGET" != /* ]]; then
    echo "ERROR: --global-hf must be an absolute path."
    exit 1
fi
if [[ "$(basename "$GLOBAL_HF_TARGET")" != "hf.sh" ]]; then
    echo "ERROR: --global-hf target must end with hf.sh."
    exit 1
fi
if [[ "$SYNC_GLOBAL_HF" -eq 1 && ! "$HF_SOURCE_SHA256" =~ ^[a-f0-9]{64}$ ]]; then
    echo "ERROR: --hf-sha256 must be a valid 64-char hex SHA-256."
    exit 1
fi

INSTALL_PLUGIN="/usr/local/cpanel/scripts/install_plugin"
if [[ ! -x "$INSTALL_PLUGIN" ]]; then
    echo "ERROR: cPanel install_plugin script not found."
    exit 1
fi

if [[ ! -f "${PLUGIN_DIR}/install.json" ]]; then
    echo "ERROR: install.json not found in plugin directory."
    exit 1
fi

if [[ ! -d "${PLUGIN_DIR}/${PLUGIN_ID}" ]]; then
    echo "ERROR: ${PLUGIN_ID} directory not found in plugin directory."
    exit 1
fi

HELPER_SOURCE="${PLUGIN_DIR}/scripts/hf-runweblogs-safe.sh"
if [[ ! -f "$HELPER_SOURCE" ]]; then
    echo "ERROR: helper script not found: $HELPER_SOURCE"
    exit 1
fi

TARGET_DIR="/usr/local/cpanel/base/frontend/${THEME}/${PLUGIN_ID}"
echo "Installing frontend files to: $TARGET_DIR"
install -d -m 0755 "$TARGET_DIR"
if command -v rsync >/dev/null 2>&1; then
    rsync -a --delete "${PLUGIN_DIR}/${PLUGIN_ID}/" "${TARGET_DIR}/"
else
    find "$TARGET_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    cp -a "${PLUGIN_DIR}/${PLUGIN_ID}/." "$TARGET_DIR/"
fi
chown -R root:root "$TARGET_DIR"

GLOBAL_HF_VERSION_LINE=""
if [[ "$SYNC_GLOBAL_HF" -eq 1 ]]; then
    TMP_HF="$(mktemp /tmp/hforensic_hf.XXXXXX)"
    cleanup_tmp_hf() {
        rm -f -- "$TMP_HF"
    }
    trap cleanup_tmp_hf EXIT

    echo "Downloading hf.sh from: $HF_SOURCE_URL"
    download_hf_script "$HF_SOURCE_URL" "$TMP_HF"
    chmod 0755 "$TMP_HF"
    validate_hf_script "$TMP_HF" "$HF_SOURCE_SHA256"

    GLOBAL_HF_DIR="$(dirname "$GLOBAL_HF_TARGET")"
    install -d -m 0755 "$GLOBAL_HF_DIR"

    if [[ -e "$GLOBAL_HF_TARGET" && ! -f "$GLOBAL_HF_TARGET" ]]; then
        echo "ERROR: global hf target exists and is not a regular file: $GLOBAL_HF_TARGET"
        exit 1
    fi

    if [[ -f "$GLOBAL_HF_TARGET" ]] && ! cmp -s "$TMP_HF" "$GLOBAL_HF_TARGET"; then
        BACKUP_FILE="${GLOBAL_HF_TARGET}.bak.$(date +%Y%m%d%H%M%S)"
        cp -a "$GLOBAL_HF_TARGET" "$BACKUP_FILE"
        echo "Backed up existing global hf.sh to: $BACKUP_FILE"
    fi

    install -m 0755 "$TMP_HF" "$GLOBAL_HF_TARGET"
    chown root:root "$GLOBAL_HF_TARGET"
    GLOBAL_HF_VERSION_LINE="$(grep -m1 '^HF_SCRIPT_VERSION=' "$GLOBAL_HF_TARGET" 2>/dev/null || true)"

    rm -f -- "$TMP_HF"
    trap - EXIT
else
    if [[ ! -x "$GLOBAL_HF_TARGET" ]]; then
        echo "ERROR: --no-global-hf used, but no executable hf.sh was found at: $GLOBAL_HF_TARGET"
        exit 1
    fi
    validate_hf_script "$GLOBAL_HF_TARGET"
    GLOBAL_HF_VERSION_LINE="$(grep -m1 '^HF_SCRIPT_VERSION=' "$GLOBAL_HF_TARGET" 2>/dev/null || true)"
fi

HELPER_TARGET="/usr/local/bin/hf-runweblogs-safe"
install -m 0755 "$HELPER_SOURCE" "$HELPER_TARGET"
chown root:root "$HELPER_TARGET"

SUDOERS_FILE="/etc/sudoers.d/hforensic_runweblogs"
TMP_SUDOERS="$(mktemp /tmp/hforensic_sudoers.XXXXXX)"
cat > "$TMP_SUDOERS" <<'SUDOERS'
# Allow cPanel users to trigger log refresh only through audited wrapper.
Defaults!/usr/local/bin/hf-runweblogs-safe !requiretty
ALL ALL=(root) NOPASSWD: /usr/local/bin/hf-runweblogs-safe [A-Za-z0-9_][A-Za-z0-9_.-]*
SUDOERS

chmod 0440 "$TMP_SUDOERS"
if command -v visudo >/dev/null 2>&1; then
    visudo -cf "$TMP_SUDOERS" >/dev/null
fi
install -m 0440 "$TMP_SUDOERS" "$SUDOERS_FILE"
rm -f "$TMP_SUDOERS"

echo "Registering plugin menu entry for theme: $THEME"
"$INSTALL_PLUGIN" "$PLUGIN_DIR" "--theme=${THEME}"

echo "Plugin installed."
echo "Global hf.sh path: $GLOBAL_HF_TARGET"
if [[ -n "$GLOBAL_HF_VERSION_LINE" ]]; then
    echo "Global $GLOBAL_HF_VERSION_LINE"
fi
echo "Open cPanel > Files > ${PLUGIN_LABEL}"
