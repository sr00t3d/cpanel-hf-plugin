#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - Runner v1.0.0                                           ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Run runner for High Forensic                                   ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
RUN_HFORENSIC_VERSION="2026-03-06-r4"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"
HF_SCRIPT="${HF_SCRIPT_PATH:-/usr/local/bin/hf.sh}"

usage() {
    cat <<'USAGE'
Usage:
  run_hforensic.sh --version
  run_hforensic.sh <cpanel_user> <file_path>
USAGE
}

if [[ $# -eq 1 ]]; then
    case "$1" in
        -V|--version)
            echo "$RUN_HFORENSIC_VERSION"
            exit 0
            ;;
        -h|--help)
            usage
            exit 0
            ;;
    esac
fi

if [[ $# -ne 2 ]]; then
    usage
    exit 64
fi

CP_USER="$1"
RAW_PATH="$2"

if [[ ! "$CP_USER" =~ ^[a-zA-Z0-9_][a-zA-Z0-9._-]{0,31}$ ]]; then
    echo "ERROR: invalid cPanel user."
    exit 65
fi

if [[ ! -x "$HF_SCRIPT" ]]; then
    echo "ERROR: hf.sh not found or not executable: $HF_SCRIPT"
    exit 66
fi

if ! grep -q 'HF_USER_MODE_FTP_READY=1' "$HF_SCRIPT" 2>/dev/null; then
    echo "ERROR: hf.sh is outdated for user-mode FTP detection."
    exit 66
fi

if ! grep -q '^search_user_ftp_logs()' "$HF_SCRIPT" 2>/dev/null; then
    echo "ERROR: hf.sh missing user FTP log scanner function."
    exit 66
fi

RESOLVED_PATH="$(realpath -- "$RAW_PATH" 2>/dev/null || true)"
if [[ -z "$RESOLVED_PATH" || ! -f "$RESOLVED_PATH" ]]; then
    echo "ERROR: file not found."
    exit 67
fi

HOME_PREFIX="/home/${CP_USER}/"
if [[ "$RESOLVED_PATH" != "$HOME_PREFIX"* ]]; then
    echo "ERROR: file must be inside ${HOME_PREFIX}."
    exit 68
fi

FILE_SIZE="$(stat -c '%s' "$RESOLVED_PATH")"
MAX_SIZE=$((10 * 1024 * 1024))
if [[ "$FILE_SIZE" -gt "$MAX_SIZE" ]]; then
    echo "ERROR: file too large. Max size: 10 MiB."
    exit 69
fi

LANG_HINT="${HF_UI_LANG:-${HF_LANG:-}}"
if [[ -n "$LANG_HINT" ]]; then
    exec env HF_UNPRIVILEGED=1 HF_AUDIT_USER="$CP_USER" HF_UI_LANG="$LANG_HINT" HF_LANG="$LANG_HINT" "$HF_SCRIPT" --mode=user --no-color "$RESOLVED_PATH"
fi

exec env HF_UNPRIVILEGED=1 HF_AUDIT_USER="$CP_USER" "$HF_SCRIPT" --mode=user --no-color "$RESOLVED_PATH"
