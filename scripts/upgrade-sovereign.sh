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

format_env_value() {
    local value="$1"

    if [[ "$value" == \"*\" || "$value" == \'*\' ]]; then
        printf '%s' "$value"
        return
    fi

    if [[ "$value" =~ [[:space:]#] ]]; then
        value="${value//\\/\\\\}"
        value="${value//\"/\\\"}"
        printf '"%s"' "$value"
        return
    fi

    printf '%s' "$value"
}

strip_env_quotes() {
    local value="$1"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

strip_image_tag() {
    local image="$1"
    local last_segment
    if [[ "$image" == *@* ]]; then
        printf '%s' "$image"
        return
    fi
    last_segment="${image##*/}"
    if [[ "$last_segment" == *:* ]]; then
        printf '%s' "${image%:*}"
        return
    fi
    printf '%s' "$image"
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
    local value
    value="$(format_env_value "$2")"

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

configure_database_env() {
    set_env_var_if_empty "DB_HOST" "coolify-db"
    set_env_var_if_empty "DB_PORT" "5432"
    set_env_var_if_empty "DB_DATABASE" "coolify"
    set_env_var_if_empty "DB_USERNAME" "coolify"

    set_env_var_if_empty "LEDGER_DB_CONNECTION" "pgsql"
    set_env_var_if_empty "LEDGER_DB_HOST" "$(get_env_var DB_HOST)"
    set_env_var_if_empty "LEDGER_DB_PORT" "$(get_env_var DB_PORT)"
    set_env_var_if_empty "LEDGER_DB_DATABASE" "$(get_env_var DB_DATABASE)"
    set_env_var_if_empty "LEDGER_DB_USERNAME" "$(get_env_var DB_USERNAME)"
    set_env_var_if_empty "LEDGER_DB_PASSWORD" "$(get_env_var DB_PASSWORD)"
}

public_base_url() {
    local app_url
    app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"
    if [ -n "$app_url" ] && [ "$app_url" != "http://localhost" ] && [ "$app_url" != "https://localhost" ]; then
        printf '%s' "$app_url"
        return
    fi

    local app_port host_ip
    app_port="$(strip_env_quotes "$(get_env_var APP_PORT)")"
    app_port="${app_port:-8000}"
    host_ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
    host_ip="${host_ip:-127.0.0.1}"

    printf 'http://%s:%s' "$host_ip" "$app_port"
}

generate_admin_claim() {
    if [ "${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE:-false}" != "true" ]; then
        return
    fi

    write_status "6" "Generating admin claim link"
    log "Generating one-time SimpleL1 admin claim link"

    local base_url
    base_url="$(public_base_url)"

    if ! docker exec coolify php artisan sovereign:admin-claim --auto --base-url="$base_url"; then
        log "Automatic admin claim link was not generated. Run manually after selecting an admin user:"
        log "docker exec -it coolify php artisan sovereign:admin-claim --user-id=<id> --base-url=${base_url}"
    fi
}

sync_host_domain() {
    local app_url host_domain
    app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"
    host_domain="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_DOMAIN)")"

    if [ -z "$app_url" ] || [ "$app_url" = "http://localhost" ] || [ "$app_url" = "https://localhost" ]; then
        return
    fi

    write_status "5" "Syncing host domain"
    log "Syncing host domain and panel URL"

    if ! docker exec coolify php artisan sovereign:sync-host-domain --url="$app_url" --domain="$host_domain"; then
        log "Host domain sync did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan sovereign:sync-host-domain --url=${app_url} --domain=${host_domain}"
    fi
}

sync_identity_policy() {
    write_status "4" "Syncing SL1 identity policy"
    log "Syncing SL1 identity policy"

    if ! docker exec coolify php artisan sovereign:sync-identity-policy; then
        log "SL1 identity policy sync did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan sovereign:sync-identity-policy"
    fi
}

run_migrations() {
    write_status "4" "Running database migrations"
    log "Running Coolify migrations"

    if ! docker exec coolify php artisan migrate --force; then
        log "Coolify migrations did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan migrate --force"
        return 1
    fi
}

run_host_hardening() {
    if [ "${SOVEREIGN_HARDENING:-false}" != "true" ]; then
        return
    fi

    write_status "2" "Applying host hardening"
    log "Applying Sovereign host hardening"

    APP_PORT="${APP_PORT:-$(strip_env_quotes "$(get_env_var APP_PORT)")}" \
    SOKETI_PORT="${SOKETI_PORT:-$(strip_env_quotes "$(get_env_var SOKETI_PORT)")}" \
    SOVEREIGN_HOST_DOMAIN="${SOVEREIGN_HOST_DOMAIN:-$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_DOMAIN)")}" \
    SOVEREIGN_HOST_PUBLIC_IP="${SOVEREIGN_HOST_PUBLIC_IP:-$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_PUBLIC_IP)")}" \
    bash "${SOURCE_DIR}/sovereign-host-hardening.sh"
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
download_file scripts/sovereign-host-hardening.sh "${SOURCE_DIR}/sovereign-host-hardening.sh"
chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/sovereign-host-hardening.sh"

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
HELPER_IMAGE="${HELPER_IMAGE:-ghcr.io/coollabsio/coolify-helper}"

set_env_var "COOLIFY_IMAGE" "$COOLIFY_IMAGE"
set_env_var "SOVEREIGN_REALTIME_IMAGE" "$SOVEREIGN_REALTIME_IMAGE"
set_env_var "HELPER_IMAGE" "$(strip_image_tag "$HELPER_IMAGE")"
set_env_var "SOVEREIGN_REPOSITORY" "$REPOSITORY"
set_env_var "SOVEREIGN_BRANCH" "$BRANCH"
set_env_var "AUTOUPDATE" "${AUTOUPDATE:-false}"
configure_database_env
SL1_CONNECT_CLIENT_NAME_VALUE="${SL1_CONNECT_CLIENT_NAME:-$(get_env_var SL1_CONNECT_CLIENT_NAME)}"
SL1_CONNECT_CLIENT_NAME_VALUE="${SL1_CONNECT_CLIENT_NAME_VALUE:-Sovereign-Coolify}"
set_env_var "SL1_CONNECT_ISSUER" "${SL1_CONNECT_ISSUER:-$(get_env_var SL1_CONNECT_ISSUER)}"
set_env_var "SL1_CONNECT_CLIENT_ID" "${SL1_CONNECT_CLIENT_ID:-$(get_env_var SL1_CONNECT_CLIENT_ID)}"
set_env_var "SL1_CONNECT_CLIENT_NAME" "$SL1_CONNECT_CLIENT_NAME_VALUE"
set_env_var "SL1_CONNECT_CALLBACK_PATH" "${SL1_CONNECT_CALLBACK_PATH:-$(get_env_var SL1_CONNECT_CALLBACK_PATH)}"
set_env_var "SL1_CONNECT_TIMEOUT" "${SL1_CONNECT_TIMEOUT:-$(get_env_var SL1_CONNECT_TIMEOUT)}"

run_host_hardening

if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Creating coolify network"
    docker network create --attachable coolify >/dev/null
fi

COMPOSE_FILES=(
    -f "${SOURCE_DIR}/docker-compose.yml"
    -f "${SOURCE_DIR}/docker-compose.prod.yml"
)
if [ -f "${SOURCE_DIR}/docker-compose.custom.yml" ]; then
    COMPOSE_FILES+=(-f "${SOURCE_DIR}/docker-compose.custom.yml")
fi
COMPOSE_FILES+=(-f "${SOURCE_DIR}/docker-compose.sovereign.prod.yml")

write_status "2" "Pulling images"
log "Pulling images"
COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" pull

write_status "3" "Starting containers"
log "Starting containers"
COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" up -d --remove-orphans --wait --wait-timeout 120

run_migrations
sync_identity_policy
sync_host_domain
generate_admin_claim

write_status "done" "Sovereign Coolify upgrade complete"
log "Sovereign Coolify upgrade complete"
