#!/usr/bin/env bash
# Recover from interrupted sovereign install (empty DB_PASSWORD, broken DB volume).
#
#   export SOVEREIGN_RUNTIME_CONVERGE_OWNER=true
#   export SOVEREIGN_ASSUME_YES=true
#   bash /tmp/coolify-sovereign/scripts/sovereign-recover-runtime.sh

set -euo pipefail

SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="${SOURCE_DIR}/.env"
INSTALL_ROOT="${COOLIFY_INSTALL_ROOT:-/data/coolify}"

if [ "$(id -u)" -ne 0 ]; then
    echo "run as root" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
export SOVEREIGN_LOCAL_REPO_PATH="${SOVEREIGN_LOCAL_REPO_PATH:-$(cd "${SCRIPT_DIR}/.." && pwd)}"
export SOVEREIGN_RUNTIME_CONVERGE_OWNER="${SOVEREIGN_RUNTIME_CONVERGE_OWNER:-true}"
export SOVEREIGN_INSTALL_MODE="${SOVEREIGN_INSTALL_MODE:-refresh}"

echo "[recover] syncing runtime files and re-running converge"
exec bash "${SCRIPT_DIR}/install-sovereign.sh"
