#!/usr/bin/env bash
# Clone vv1ldd/coolify (sovereign branch) and run install-sovereign.sh locally.
# Prefer this over raw curl when you need disk encryption scripts or offline-tolerant installs.
#
#   export SOVEREIGN_RUNTIME_CONVERGE_OWNER=true
#   export SOVEREIGN_ASSUME_YES=true
#   export SOVEREIGN_HOST_DOMAIN=ops.example.com
#   curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/bootstrap-sovereign-from-git.sh \
#     -o /tmp/bootstrap-sovereign-from-git.sh
#   bash /tmp/bootstrap-sovereign-from-git.sh
#
# Or after git is installed:
#   git clone --depth 1 -b sovereign https://github.com/vv1ldd/coolify.git /tmp/coolify-sovereign
#   bash /tmp/coolify-sovereign/scripts/install-sovereign.sh

set -euo pipefail

REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
CLONE_DIR="${SOVEREIGN_GIT_CLONE_DIR:-/tmp/coolify-sovereign}"
GIT_URL="${SOVEREIGN_GIT_URL:-https://github.com/${REPOSITORY}.git}"

if [ "$(id -u)" -ne 0 ]; then
    echo "ERROR: run as root (or: curl ... | sudo -E bash)" >&2
    exit 1
fi

ensure_git() {
    if command -v git >/dev/null 2>&1; then
        return 0
    fi
    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq git
        return 0
    fi
    echo "ERROR: git is required. Install git and rerun." >&2
    exit 1
}

sync_repo() {
  if [ -d "${CLONE_DIR}/.git" ]; then
    git -C "$CLONE_DIR" fetch --depth 1 origin "$BRANCH"
    git -C "$CLONE_DIR" checkout -B "$BRANCH" FETCH_HEAD
    return 0
  fi

  git clone --depth 1 -b "$BRANCH" "$GIT_URL" "$CLONE_DIR"
}

ensure_git
sync_repo

export SOVEREIGN_LOCAL_REPO_PATH="$CLONE_DIR"
exec bash "${CLONE_DIR}/scripts/install-sovereign.sh"
