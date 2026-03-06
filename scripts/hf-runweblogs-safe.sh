#!/usr/bin/env bash
# ╔═══════════════════════════════════════════════════════════════════════════╗
# ║                                                                           ║
# ║   High Forensic - RunLogs v1.0.0                                          ║
# ║                                                                           ║
# ╠═══════════════════════════════════════════════════════════════════════════╣
# ║   Autor:   Percio Castelo                                                 ║
# ║   Contato: percio@evolya.com.br | contato@perciocastelo.com.br            ║
# ║   Web:     https://perciocastelo.com.br                                   ║
# ║                                                                           ║
# ║   Função:  Run and update Weblogs from cPanel User                        ║
# ║                                                                           ║
# ╚═══════════════════════════════════════════════════════════════════════════╝

set -euo pipefail
HF_RUNWEBLOGS_SAFE_VERSION="2026-03-06-r2"
PATH="/usr/sbin:/usr/bin:/sbin:/bin"

usage() {
    cat <<'EOF'
Usage:
  hf-runweblogs-safe.sh --version
  hf-runweblogs-safe.sh <cpanel_user>
EOF
}

if [[ $# -eq 1 ]]; then
    case "$1" in
        -V|--version)
            echo "$HF_RUNWEBLOGS_SAFE_VERSION"
            exit 0
            ;;
        -h|--help)
            usage
            exit 0
            ;;
    esac
fi

if [[ $# -ne 1 ]]; then
    usage
    exit 64
fi

CP_USER="$1"
if [[ ! "$CP_USER" =~ ^[a-zA-Z0-9_][a-zA-Z0-9._-]{0,31}$ ]]; then
    echo "ERROR: invalid cPanel user."
    exit 65
fi

if [[ ! -f "/var/cpanel/users/$CP_USER" ]]; then
    echo "ERROR: cPanel user not found."
    exit 66
fi

# This helper must only run as root through sudo.
if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: must run as root."
    exit 67
fi

INVOKER="${SUDO_USER:-}"
if [[ -z "$INVOKER" ]]; then
    echo "ERROR: must be called via sudo."
    exit 68
fi

# Each user may only trigger refresh for themselves.
if [[ "$INVOKER" != "$CP_USER" ]]; then
    echo "ERROR: invoker user mismatch."
    exit 69
fi

RUNWEBLOGS="/usr/local/cpanel/scripts/runweblogs"
if [[ ! -x "$RUNWEBLOGS" ]]; then
    echo "ERROR: runweblogs script not found."
    exit 70
fi

MIN_INTERVAL="${HF_RUNWEBLOGS_MIN_INTERVAL:-180}"
if [[ ! "$MIN_INTERVAL" =~ ^[0-9]+$ ]]; then
    MIN_INTERVAL=180
fi

STATE_DIR="/var/run/hforensic-runweblogs"
install -d -m 0755 "$STATE_DIR"

LOCK_FILE="${STATE_DIR}/${CP_USER}.lock"
STAMP_FILE="${STATE_DIR}/${CP_USER}.last"
exec 9>"$LOCK_FILE"

if command -v flock >/dev/null 2>&1; then
    if ! flock -w 5 9; then
        echo "INFO: refresh already in progress."
        exit 0
    fi
fi

NOW="$(date +%s)"
if [[ -f "$STAMP_FILE" ]]; then
    LAST="$(cat "$STAMP_FILE" 2>/dev/null || true)"
    if [[ "$LAST" =~ ^[0-9]+$ ]]; then
        DIFF=$((NOW - LAST))
        if [[ "$DIFF" -lt "$MIN_INTERVAL" ]]; then
            echo "OK: recently refreshed ${DIFF}s ago."
            exit 0
        fi
    fi
fi

"$RUNWEBLOGS" "$CP_USER" >/dev/null 2>&1 || {
    echo "ERROR: runweblogs execution failed."
    exit 71
}

echo "$NOW" > "$STAMP_FILE"
echo "OK: logs refreshed for ${CP_USER}."