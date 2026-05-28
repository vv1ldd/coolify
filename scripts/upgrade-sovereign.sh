#!/usr/bin/env bash

set -euo pipefail

DATE="$(date +"%Y%m%d-%H%M%S")"
SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="${SOURCE_DIR}/.env"
STATUS_FILE="${SOURCE_DIR}/.upgrade-sovereign-status"
LOG_FILE="${SOURCE_DIR}/upgrade-sovereign-${DATE}.log"
REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
RAW_BASE="${SOVEREIGN_RAW_BASE:-https://raw.githubusercontent.com/${REPOSITORY}/${BRANCH}}"

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

write_status() {
    echo "$1|$2|$(date -Iseconds)" > "$STATUS_FILE"
}

get_env_var() {
    local key="$1"
    if [ -f "$ENV_FILE" ]; then
        grep -E "^${key}=" "$ENV_FILE" | tail -n 1 | cut -d '=' -f 2- || true
    fi
}

download_file() {
    local source_path="$1"
    local target_path="$2"
    log "Downloading ${source_path}"
    curl -fsSL "${RAW_BASE}/${source_path}" -o "$target_path"
}

merge_env_production() {
    if [ -f "${SOURCE_DIR}/.env.production" ]; then
        awk -F '=' '!seen[$1]++' "$ENV_FILE" "${SOURCE_DIR}/.env.production" > "${ENV_FILE}.tmp"
        mv "${ENV_FILE}.tmp" "$ENV_FILE"
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

if [ "$EUID" -ne 0 ]; then
    echo "Please run this script as root or with sudo."
    exit 1
fi

mkdir -p "$SOURCE_DIR"
touch "$LOG_FILE"

log "Starting Sovereign Coolify upgrade"
write_status "1" "Downloading compose files"

download_file docker-compose.yml "${SOURCE_DIR}/docker-compose.yml"
download_file docker-compose.prod.yml "${SOURCE_DIR}/docker-compose.prod.yml"
download_file docker-compose.sovereign.prod.yml "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
download_file .env.production "${SOURCE_DIR}/.env.production"
download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"

if [ ! -f "$ENV_FILE" ]; then
    cp "${SOURCE_DIR}/.env.production" "$ENV_FILE"
else
    cp "$ENV_FILE" "${ENV_FILE}-${DATE}"
    merge_env_production
fi

COOLIFY_IMAGE="${COOLIFY_IMAGE:-$(get_env_var COOLIFY_IMAGE)}"
COOLIFY_IMAGE="${COOLIFY_IMAGE:-ghcr.io/${REPOSITORY}:sovereign}"
SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-$(get_env_var SOVEREIGN_REALTIME_IMAGE)}"
SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-ghcr.io/coollabsio/coolify-realtime:1.0.13}"
HELPER_IMAGE="${HELPER_IMAGE:-$(get_env_var HELPER_IMAGE)}"
HELPER_IMAGE="${HELPER_IMAGE:-ghcr.io/coollabsio/coolify-helper:latest}"

set_env_var "COOLIFY_IMAGE" "$COOLIFY_IMAGE"
set_env_var "SOVEREIGN_REALTIME_IMAGE" "$SOVEREIGN_REALTIME_IMAGE"
set_env_var "HELPER_IMAGE" "$HELPER_IMAGE"
set_env_var "SOVEREIGN_REPOSITORY" "$REPOSITORY"
set_env_var "SOVEREIGN_BRANCH" "$BRANCH"
set_env_var "AUTOUPDATE" "${AUTOUPDATE:-false}"
set_env_var "SL1_CONNECT_ISSUER" "${SL1_CONNECT_ISSUER:-$(get_env_var SL1_CONNECT_ISSUER)}"
set_env_var "SL1_CONNECT_CLIENT_ID" "${SL1_CONNECT_CLIENT_ID:-$(get_env_var SL1_CONNECT_CLIENT_ID)}"
set_env_var "SL1_CONNECT_CLIENT_NAME" "${SL1_CONNECT_CLIENT_NAME:-$(get_env_var SL1_CONNECT_CLIENT_NAME)}"
set_env_var "SL1_CONNECT_CALLBACK_PATH" "${SL1_CONNECT_CALLBACK_PATH:-$(get_env_var SL1_CONNECT_CALLBACK_PATH)}"
set_env_var "SL1_CONNECT_TIMEOUT" "${SL1_CONNECT_TIMEOUT:-$(get_env_var SL1_CONNECT_TIMEOUT)}"

if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Creating coolify network"
    docker network create --attachable coolify >/dev/null
fi

COMPOSE_FILES=(
    -f "${SOURCE_DIR}/docker-compose.yml"
    -f "${SOURCE_DIR}/docker-compose.prod.yml"
    -f "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
)

write_status "2" "Pulling images"
log "Pulling images"
COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" pull

write_status "3" "Starting containers"
log "Starting containers"
COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" up -d --remove-orphans --wait --wait-timeout 120

write_status "done" "Sovereign Coolify upgrade complete"
log "Sovereign Coolify upgrade complete"
