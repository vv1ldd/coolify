#!/usr/bin/env bash
# Runs once on first boot after encrypted autoinstall (systemd oneshot).

set -euo pipefail

MARKER=/etc/sovereign-disk-ready
ENV_FILE=/etc/sovereign/firstboot.env
LOG=/var/log/sovereign-firstboot.log
REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
CLONE_DIR="${SOVEREIGN_GIT_CLONE_DIR:-/tmp/coolify-sovereign}"
GIT_URL="${SOVEREIGN_GIT_URL:-https://github.com/${REPOSITORY}.git}"

exec >>"$LOG" 2>&1
echo "=== sovereign-firstboot $(date -Iseconds) ==="

[ -f "$MARKER" ] || exit 0

if [ -f "$ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    source "$ENV_FILE"
    set +a
fi

export SOVEREIGN_RUNTIME_CONVERGE_OWNER="${SOVEREIGN_RUNTIME_CONVERGE_OWNER:-true}"
export SOVEREIGN_DISK_ENCRYPT="${SOVEREIGN_DISK_ENCRYPT:-true}"
export SOVEREIGN_ASSUME_YES="${SOVEREIGN_ASSUME_YES:-true}"

if ! command -v git >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq git
fi

if [ -d "${CLONE_DIR}/.git" ]; then
    git -C "$CLONE_DIR" fetch --depth 1 origin "$BRANCH"
    git -C "$CLONE_DIR" checkout -B "$BRANCH" FETCH_HEAD
else
    git clone --depth 1 -b "$BRANCH" "$GIT_URL" "$CLONE_DIR"
fi

export SOVEREIGN_LOCAL_REPO_PATH="$CLONE_DIR"
bash "${CLONE_DIR}/scripts/install-sovereign.sh"

rm -f "$MARKER"
systemctl disable sovereign-firstboot.service 2>/dev/null || true

echo "=== sovereign-firstboot complete ==="
