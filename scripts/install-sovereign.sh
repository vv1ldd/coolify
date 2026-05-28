#!/usr/bin/env bash

set -euo pipefail

DATE="$(date +"%Y%m%d-%H%M%S")"
INSTALL_ROOT="${COOLIFY_INSTALL_ROOT:-/data/coolify}"
SOURCE_DIR="${COOLIFY_SOURCE_DIR:-${INSTALL_ROOT}/source}"
ENV_FILE="${SOURCE_DIR}/.env"
LOG_FILE="${SOURCE_DIR}/installation-sovereign-${DATE}.log"
REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
RAW_BASE="${SOVEREIGN_RAW_BASE:-https://raw.githubusercontent.com/${REPOSITORY}/${BRANCH}}"
COOLIFY_IMAGE="${COOLIFY_IMAGE:-ghcr.io/${REPOSITORY}:sovereign}"
SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-ghcr.io/coollabsio/coolify-realtime:1.0.13}"
HELPER_IMAGE="${HELPER_IMAGE:-ghcr.io/coollabsio/coolify-helper:latest}"
APP_PORT="${APP_PORT:-8000}"
SOKETI_PORT="${SOKETI_PORT:-6001}"
AUTOUPDATE="${AUTOUPDATE:-false}"
SL1_CONNECT_ISSUER="${SL1_CONNECT_ISSUER:-https://simplel1.online}"
SL1_CONNECT_CLIENT_ID="${SL1_CONNECT_CLIENT_ID:-coolify.sovereign}"
SL1_CONNECT_CLIENT_NAME="${SL1_CONNECT_CLIENT_NAME:-Sovereign Coolify}"
SL1_CONNECT_CALLBACK_PATH="${SL1_CONNECT_CALLBACK_PATH:-/auth/sl1/callback}"
SL1_CONNECT_TIMEOUT="${SL1_CONNECT_TIMEOUT:-10}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

download_file() {
    local source_path="$1"
    local target_path="$2"
    log "Downloading ${source_path}"
    curl -fsSL "${RAW_BASE}/${source_path}" -o "$target_path"
}

install_docker() {
    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        log "Docker and Docker Compose are already installed"
        return
    fi

    log "Installing Docker Engine"
    curl -fsSL https://get.docker.com | sh

    if command -v systemctl >/dev/null 2>&1; then
        systemctl enable --now docker
    fi
}

login_to_registry() {
    if [ -n "${GHCR_USERNAME:-}" ] && [ -n "${GHCR_TOKEN:-}" ]; then
        log "Logging in to ghcr.io as ${GHCR_USERNAME}"
        echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USERNAME" --password-stdin
    fi
}

merge_env_production() {
    if [ -f "$ENV_FILE" ]; then
        cp "$ENV_FILE" "${ENV_FILE}-${DATE}"
        awk -F '=' '!seen[$1]++' "$ENV_FILE" "${SOURCE_DIR}/.env.production" > "${ENV_FILE}.tmp"
        mv "${ENV_FILE}.tmp" "$ENV_FILE"
    else
        cp "${SOURCE_DIR}/.env.production" "$ENV_FILE"
    fi
}

set_env_var() {
    local key="$1"
    local value="$2"

    if grep -q "^${key}=" "$ENV_FILE"; then
        awk -v key="$key" -v value="$value" '
            BEGIN { FS = OFS = "=" }
            $1 == key { print key "=" value; next }
            { print }
        ' "$ENV_FILE" > "${ENV_FILE}.tmp"
        mv "${ENV_FILE}.tmp" "$ENV_FILE"
    else
        printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
    fi
}

set_env_var_if_empty() {
    local key="$1"
    local value="$2"

    if ! grep -q "^${key}=" "$ENV_FILE" || grep -q "^${key}=$" "$ENV_FILE"; then
        set_env_var "$key" "$value"
    fi
}

prepare_ssh_key() {
    local current_user="${SUDO_USER:-root}"
    local key_path="${INSTALL_ROOT}/ssh/keys/id.${current_user}@host.docker.internal"

    mkdir -p /root/.ssh
    chmod 700 /root/.ssh
    touch /root/.ssh/authorized_keys
    chmod 600 /root/.ssh/authorized_keys

    if [ ! -f "$key_path" ]; then
        log "Generating localhost SSH key for Coolify"
        ssh-keygen -t ed25519 -a 100 -f "$key_path" -q -N "" -C coolify
        sed -i "/coolify/d" /root/.ssh/authorized_keys
        cat "${key_path}.pub" >> /root/.ssh/authorized_keys
        rm -f "${key_path}.pub"
    fi
}

if [ "$EUID" -ne 0 ]; then
    echo "Please run this script as root or with sudo."
    exit 1
fi

mkdir -p "${SOURCE_DIR}" "${INSTALL_ROOT}"/{ssh,applications,databases,backups,services,proxy,sentinel}
mkdir -p "${INSTALL_ROOT}/ssh/keys" "${INSTALL_ROOT}/ssh/mux" "${INSTALL_ROOT}/proxy/dynamic"
touch "$LOG_FILE"

echo ""
echo "=========================================="
echo "   Sovereign Coolify Installation"
echo "=========================================="
echo ""
echo "Repository: ${REPOSITORY}"
echo "Branch:     ${BRANCH}"
echo "Image:      ${COOLIFY_IMAGE}"
echo ""

install_docker
login_to_registry

log "Downloading Sovereign Coolify configuration"
download_file docker-compose.yml "${SOURCE_DIR}/docker-compose.yml"
download_file docker-compose.prod.yml "${SOURCE_DIR}/docker-compose.prod.yml"
download_file docker-compose.sovereign.prod.yml "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
download_file .env.production "${SOURCE_DIR}/.env.production"
download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"

merge_env_production

set_env_var_if_empty "APP_ID" "$(openssl rand -hex 16)"
set_env_var_if_empty "APP_KEY" "base64:$(openssl rand -base64 32)"
set_env_var_if_empty "DB_PASSWORD" "$(openssl rand -base64 32)"
set_env_var_if_empty "REDIS_PASSWORD" "$(openssl rand -base64 32)"
set_env_var_if_empty "PUSHER_APP_ID" "$(openssl rand -hex 32)"
set_env_var_if_empty "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
set_env_var_if_empty "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"

set_env_var "APP_PORT" "$APP_PORT"
set_env_var "SOKETI_PORT" "$SOKETI_PORT"
set_env_var "COOLIFY_IMAGE" "$COOLIFY_IMAGE"
set_env_var "SOVEREIGN_REALTIME_IMAGE" "$SOVEREIGN_REALTIME_IMAGE"
set_env_var "HELPER_IMAGE" "$HELPER_IMAGE"
set_env_var "SOVEREIGN_REPOSITORY" "$REPOSITORY"
set_env_var "SOVEREIGN_BRANCH" "$BRANCH"
set_env_var "AUTOUPDATE" "$AUTOUPDATE"
set_env_var "SL1_CONNECT_ISSUER" "$SL1_CONNECT_ISSUER"
set_env_var "SL1_CONNECT_CLIENT_ID" "$SL1_CONNECT_CLIENT_ID"
set_env_var "SL1_CONNECT_CLIENT_NAME" "$SL1_CONNECT_CLIENT_NAME"
set_env_var "SL1_CONNECT_CALLBACK_PATH" "$SL1_CONNECT_CALLBACK_PATH"
set_env_var "SL1_CONNECT_TIMEOUT" "$SL1_CONNECT_TIMEOUT"

if [ -n "${ROOT_USERNAME:-}" ] && [ -n "${ROOT_USER_EMAIL:-}" ] && [ -n "${ROOT_USER_PASSWORD:-}" ]; then
    set_env_var "ROOT_USERNAME" "$ROOT_USERNAME"
    set_env_var "ROOT_USER_EMAIL" "$ROOT_USER_EMAIL"
    set_env_var "ROOT_USER_PASSWORD" "$ROOT_USER_PASSWORD"
fi

if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Creating coolify network"
    docker network create --attachable coolify >/dev/null
fi

prepare_ssh_key
chown -R 9999:root "$INSTALL_ROOT"
chmod -R 700 "$INSTALL_ROOT"

log "Starting Sovereign Coolify"
bash "${SOURCE_DIR}/upgrade-sovereign.sh"

echo ""
echo "Sovereign Coolify installation complete."
echo "Open: http://$(hostname -I | awk '{print $1}'):${APP_PORT}"
echo "Logs: ${LOG_FILE}"
