#!/usr/bin/env bash

set -euo pipefail

DATE="$(date +"%Y%m%d-%H%M%S")"
REPO_DIR="${SOVEREIGN_REPO_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
INSTALL_ROOT="${COOLIFY_INSTALL_ROOT:-/data/coolify}"
SOURCE_DIR="${COOLIFY_SOURCE_DIR:-${INSTALL_ROOT}/source}"
ENV_FILE="${SOURCE_DIR}/.env"
LOG_FILE="${SOURCE_DIR}/update-sovereign-${DATE}.log"
STATUS_FILE="${SOURCE_DIR}/.update-sovereign-status"
SOVEREIGN_VERBOSE="${SOVEREIGN_VERBOSE:-false}"
SOVEREIGN_DNS_STEERING="${SOVEREIGN_DNS_STEERING:-dry-run}"
SOVEREIGN_SKIP_PULL="${SOVEREIGN_SKIP_PULL:-false}"
SOVEREIGN_SKIP_HEALTHCHECK="${SOVEREIGN_SKIP_HEALTHCHECK:-false}"
COOLIFY_IMAGE="${COOLIFY_IMAGE:-}"
SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-}"

if [ -z "${NO_COLOR:-}" ]; then
    C_RESET="$(printf '\033[0m')"
    C_DIM="$(printf '\033[2m')"
    C_CYAN="$(printf '\033[36m')"
    C_MAGENTA="$(printf '\033[35m')"
    C_GREEN="$(printf '\033[32m')"
    C_YELLOW="$(printf '\033[33m')"
    C_RED="$(printf '\033[31m')"
else
    C_RESET=""
    C_DIM=""
    C_CYAN=""
    C_MAGENTA=""
    C_GREEN=""
    C_YELLOW=""
    C_RED=""
fi

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

phase() {
    echo "${C_MAGENTA}>${C_RESET} ${C_CYAN}$*${C_RESET}" | tee -a "$LOG_FILE"
}

note() {
    echo "${C_DIM}   $*${C_RESET}" | tee -a "$LOG_FILE"
}

warn() {
    echo "${C_YELLOW}!${C_RESET} $*" | tee -a "$LOG_FILE"
}

die() {
    echo "${C_RED}ERROR: $*${C_RESET}" | tee -a "$LOG_FILE"
    exit 1
}

write_status() {
    printf '%s|%s|%s\n' "$1" "$2" "$(date -Iseconds)" > "$STATUS_FILE"
}

show_log_tail() {
    echo "ERROR: Command failed. Last log lines from ${LOG_FILE}:"
    tail -n 80 "$LOG_FILE" 2>/dev/null || true
}

run_logged() {
    local label="$1"
    shift

    if [ "${SOVEREIGN_VERBOSE}" = "true" ]; then
        "$@"
        return $?
    fi

    "$@" >> "$LOG_FILE" 2>&1 || {
        show_log_tail
        return 1
    }

    echo "${C_GREEN}[ok]${C_RESET} ${label}" | tee -a "$LOG_FILE"
}

require_root() {
    if [ "$EUID" -ne 0 ]; then
        die "Please run this script as root or with sudo."
    fi
}

require_file() {
    local path="$1"
    [ -f "$path" ] || die "Required file not found: ${path}"
}

get_env_var() {
    local key="$1"
    if [ -f "$ENV_FILE" ]; then
        grep -E "^${key}=" "$ENV_FILE" | tail -n 1 | cut -d '=' -f 2- || true
    fi
}

strip_env_quotes() {
    local value="$1"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

compose_files=()

build_compose_files() {
    compose_files=(
        -f "${SOURCE_DIR}/docker-compose.yml"
        -f "${SOURCE_DIR}/docker-compose.prod.yml"
    )

    if [ -f "${SOURCE_DIR}/docker-compose.custom.yml" ]; then
        compose_files+=(-f "${SOURCE_DIR}/docker-compose.custom.yml")
    fi

    compose_files+=(-f "${SOURCE_DIR}/docker-compose.sovereign.prod.yml")
}

copy_runtime_files() {
    phase "sync runtime files from repository"
    mkdir -p "$SOURCE_DIR"
    cp "${REPO_DIR}/docker-compose.yml" "${SOURCE_DIR}/docker-compose.yml"
    cp "${REPO_DIR}/docker-compose.prod.yml" "${SOURCE_DIR}/docker-compose.prod.yml"
    cp "${REPO_DIR}/docker-compose.sovereign.prod.yml" "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
    cp "${REPO_DIR}/.env.production" "${SOURCE_DIR}/.env.production"
    cp "${REPO_DIR}/scripts/upgrade-sovereign.sh" "${SOURCE_DIR}/upgrade-sovereign.sh"
    cp "${REPO_DIR}/scripts/sovereign-host-hardening.sh" "${SOURCE_DIR}/sovereign-host-hardening.sh"
    chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh" "${SOURCE_DIR}/sovereign-host-hardening.sh"

    if [ -f "$ENV_FILE" ]; then
        cp "$ENV_FILE" "${ENV_FILE}-${DATE}"
        note "Backed up .env to ${ENV_FILE}-${DATE}"
    else
        cp "${SOURCE_DIR}/.env.production" "$ENV_FILE"
        note "Created ${ENV_FILE} from .env.production"
    fi
}

resolve_images() {
    COOLIFY_IMAGE="${COOLIFY_IMAGE:-$(strip_env_quotes "$(get_env_var COOLIFY_IMAGE)")}"
    SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-$(strip_env_quotes "$(get_env_var SOVEREIGN_REALTIME_IMAGE)")}"
    COOLIFY_IMAGE="${COOLIFY_IMAGE:-ghcr.io/vv1ldd/coolify:sovereign}"
    SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-ghcr.io/coollabsio/coolify-realtime:1.0.13}"
}

docker_compose() {
    env COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" \
        docker compose --env-file "$ENV_FILE" "${compose_files[@]}" "$@"
}

pull_images() {
    if [ "$SOVEREIGN_SKIP_PULL" = "true" ]; then
        warn "Skipping image pull because SOVEREIGN_SKIP_PULL=true"
        return
    fi

    phase "pull runtime images"
    run_logged "pulled runtime images" docker_compose pull
}

restart_runtime() {
    phase "restart runtime"

    if ! docker network inspect coolify >/dev/null 2>&1; then
        log "Creating coolify network"
        docker network create --attachable coolify >/dev/null
    fi

    run_logged "started runtime containers" docker_compose up -d --remove-orphans --wait --wait-timeout 120
}

run_migrations() {
    phase "database migrations"
    run_logged "applied database migrations" docker exec coolify php artisan migrate --force
}

optimize_runtime() {
    phase "runtime cache"
    run_logged "cleared caches" docker exec coolify php artisan optimize:clear
    run_logged "cached config/routes/views" docker exec coolify php artisan optimize
}

healthcheck() {
    if [ "$SOVEREIGN_SKIP_HEALTHCHECK" = "true" ]; then
        warn "Skipping healthcheck because SOVEREIGN_SKIP_HEALTHCHECK=true"
        return
    fi

    phase "post-update healthcheck"
    local app_port app_url
    app_port="$(strip_env_quotes "$(get_env_var APP_PORT)")"
    app_port="${app_port:-8000}"
    app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"

    run_logged "container health endpoint" docker exec coolify curl --fail --max-time 10 http://127.0.0.1:8080/api/health

    if [ -n "$app_url" ] && [ "$app_url" != "http://localhost" ] && [ "$app_url" != "https://localhost" ]; then
        if curl -fsSI --max-time 15 "${app_url}/api/health" >> "$LOG_FILE" 2>&1; then
            echo "${C_GREEN}[ok]${C_RESET} public health endpoint ${app_url}/api/health" | tee -a "$LOG_FILE"
        else
            warn "Public health endpoint did not pass yet: ${app_url}/api/health"
        fi
    else
        note "Local app URL: http://127.0.0.1:${app_port}/api/health"
    fi
}

dns_steering() {
    case "$SOVEREIGN_DNS_STEERING" in
        skip|false|off)
            note "DNS steering skipped."
            return
            ;;
        dry-run)
            phase "DNS steering dry-run"
            run_logged "evaluated DNS steering dry-run" docker exec coolify php artisan dns:steering:evaluate --json
            ;;
        apply)
            phase "DNS steering apply"
            run_logged "applied DNS steering policies" docker exec coolify php artisan dns:steering:evaluate --apply --json
            ;;
        *)
            die "Invalid SOVEREIGN_DNS_STEERING=${SOVEREIGN_DNS_STEERING}. Use dry-run, apply, or skip."
            ;;
    esac
}

main() {
    require_root
    mkdir -p "$SOURCE_DIR"
    touch "$LOG_FILE"

    phase "Sovereign Coolify repository update"
    note "Repo:       ${REPO_DIR}"
    note "Source:     ${SOURCE_DIR}"
    note "Log:        ${LOG_FILE}"
    note "DNS mode:   ${SOVEREIGN_DNS_STEERING}"

    require_file "${REPO_DIR}/docker-compose.yml"
    require_file "${REPO_DIR}/docker-compose.prod.yml"
    require_file "${REPO_DIR}/docker-compose.sovereign.prod.yml"
    require_file "${REPO_DIR}/.env.production"
    require_file "${REPO_DIR}/scripts/upgrade-sovereign.sh"

    write_status "started" "Repository update started"
    copy_runtime_files
    resolve_images
    build_compose_files

    note "Coolify image: ${COOLIFY_IMAGE}"
    pull_images
    restart_runtime
    run_migrations
    optimize_runtime
    healthcheck
    dns_steering

    write_status "done" "Repository update complete"
    phase "update complete"
    note "Log: ${LOG_FILE}"
}

main "$@"
