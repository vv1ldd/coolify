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
SOVEREIGN_INSTALL_MODE="${SOVEREIGN_INSTALL_MODE:-auto}"
SL1_CONNECT_ISSUER="${SL1_CONNECT_ISSUER:-https://simplel1.online}"
SL1_CONNECT_CLIENT_ID="${SL1_CONNECT_CLIENT_ID:-coolify.sovereign}"
SL1_CONNECT_CLIENT_NAME="${SL1_CONNECT_CLIENT_NAME:-Sovereign-Coolify}"
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

check_host_resources() {
    if [ -r /proc/meminfo ]; then
        local mem_kb
        mem_kb="$(awk '/MemTotal/ { print $2 }' /proc/meminfo)"
        if [ "${mem_kb:-0}" -lt 1800000 ]; then
            log "WARNING: Sovereign Coolify is likely unstable below 2 GB RAM. Current host reports $((mem_kb / 1024)) MB."
        fi
    fi
}

existing_coolify_detected() {
    if [ -f "$ENV_FILE" ] || [ -f "${SOURCE_DIR}/docker-compose.yml" ]; then
        return 0
    fi

    if command -v docker >/dev/null 2>&1; then
        if docker ps -a --format '{{.Names}}' 2>/dev/null | grep -Eq '^(coolify|coolify-db|coolify-redis)$'; then
            return 0
        fi

        if docker volume ls --format '{{.Name}}' 2>/dev/null | grep -Eq '^(coolify-db|coolify-redis)$'; then
            return 0
        fi
    fi

    return 1
}

run_existing_upgrade() {
    log "Existing Coolify installation detected. Running sovereign upgrade path."
    download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
    chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"

    SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE=true bash "${SOURCE_DIR}/upgrade-sovereign.sh"

    echo ""
    echo "Sovereign Coolify upgrade complete."
    echo "Use the printed CLAIM_URL to bind the existing admin to SimpleL1 Identity."
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
echo "Mode:       ${SOVEREIGN_INSTALL_MODE}"
echo ""

check_host_resources
install_docker
login_to_registry

case "$SOVEREIGN_INSTALL_MODE" in
    auto)
        if existing_coolify_detected; then
            run_existing_upgrade
            exit 0
        fi
        ;;
    upgrade)
        if ! existing_coolify_detected; then
            echo "SOVEREIGN_INSTALL_MODE=upgrade was requested, but no existing Coolify installation was detected."
            exit 1
        fi
        run_existing_upgrade
        exit 0
        ;;
    fresh)
        if existing_coolify_detected; then
            echo "SOVEREIGN_INSTALL_MODE=fresh was requested, but an existing Coolify installation was detected."
            echo "Refusing to continue to protect existing volumes and database."
            exit 1
        fi
        ;;
    *)
        echo "Invalid SOVEREIGN_INSTALL_MODE=${SOVEREIGN_INSTALL_MODE}. Use auto, fresh, or upgrade."
        exit 1
        ;;
esac

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
configure_database_env

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
