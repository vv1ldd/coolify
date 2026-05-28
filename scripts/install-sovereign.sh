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
SOVEREIGN_HARDENING="${SOVEREIGN_HARDENING:-auto}"
SOVEREIGN_HARDENING_PROFILE="${SOVEREIGN_HARDENING_PROFILE:-baseline}"
SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"
SOVEREIGN_HOST_DOMAIN="${SOVEREIGN_HOST_DOMAIN:-}"
SL1_CONNECT_ISSUER="${SL1_CONNECT_ISSUER:-https://simplel1.online}"
SL1_CONNECT_CLIENT_ID="${SL1_CONNECT_CLIENT_ID:-coolify.sovereign}"
SL1_CONNECT_CLIENT_NAME="${SL1_CONNECT_CLIENT_NAME:-Sovereign-Coolify}"
SL1_CONNECT_CALLBACK_PATH="${SL1_CONNECT_CALLBACK_PATH:-/auth/sl1/callback}"
SL1_CONNECT_TIMEOUT="${SL1_CONNECT_TIMEOUT:-10}"
SELECTED_INSTALL_MODE=""
SELECTED_ADMIN_CLAIM="false"
SELECTED_HARDENING="false"
SELECTED_APP_URL=""
SELECTED_HOST_DOMAIN="$SOVEREIGN_HOST_DOMAIN"

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

term_line() {
    printf '%b\n' "$*"
}

banner() {
    echo ""
    term_line "${C_MAGENTA}============================================================${C_RESET}"
    term_line "${C_CYAN}  SOVEREIGN COOLIFY // SL1 CONTROL-PLANE BOOTSTRAP${C_RESET}"
    term_line "${C_MAGENTA}============================================================${C_RESET}"
    term_line "${C_DIM}  identity: SL1-only  theme: cyberpunk  mode: auto-aware${C_RESET}"
    echo ""
}

section() {
    term_line "${C_CYAN}>> $*${C_RESET}"
}

note() {
    term_line "${C_DIM}   $*${C_RESET}"
}

warning() {
    term_line "${C_YELLOW}!! $*${C_RESET}"
}

die() {
    term_line "${C_RED}ERROR: $*${C_RESET}"
    exit 1
}

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

strip_env_quotes() {
    local value="$1"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

detect_public_ip() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 3 https://api.ipify.org 2>/dev/null || true
    fi
}

domain_points_to_host() {
    local domain="$1"
    local public_ip="$2"

    [ -n "$domain" ] || return 1
    [ -n "$public_ip" ] || return 1

    if command -v getent >/dev/null 2>&1; then
        getent ahosts "$domain" 2>/dev/null | awk '{ print $1 }' | grep -Fxq "$public_ip"
        return $?
    fi

    if command -v dig >/dev/null 2>&1; then
        dig +short A "$domain" 2>/dev/null | grep -Fxq "$public_ip"
        return $?
    fi

    return 1
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

sovereign_coolify_detected() {
    if [ -f "$ENV_FILE" ] && grep -Eq '^(SOVEREIGN_BRANCH|SOVEREIGN_REPOSITORY)=|^COOLIFY_IMAGE=.*(vv1ldd/coolify|:sovereign)' "$ENV_FILE"; then
        return 0
    fi

    if command -v docker >/dev/null 2>&1; then
        local image
        image="$(docker inspect coolify --format '{{.Config.Image}}' 2>/dev/null || true)"
        case "$image" in
            *vv1ldd/coolify*|*:sovereign)
                return 0
                ;;
        esac
    fi

    return 1
}

detected_install_state() {
    if sovereign_coolify_detected; then
        printf 'sovereign'
        return
    fi

    if existing_coolify_detected; then
        printf 'coolify'
        return
    fi

    printf 'fresh'
}

can_prompt() {
    [ "${SOVEREIGN_ASSUME_YES:-false}" != "true" ] && [ -r /dev/tty ] && [ -w /dev/tty ]
}

print_detection() {
    local state="$1"

    section "Host scan"
    note "Install root: ${INSTALL_ROOT}"
    note "Source dir:   ${SOURCE_DIR}"
    note "Repository:   ${REPOSITORY}"
    note "Branch:       ${BRANCH}"
    note "Image:        ${COOLIFY_IMAGE}"

    case "$state" in
        fresh)
            term_line "${C_GREEN}   Detected: clean host / no Coolify state found${C_RESET}"
            note "Recommended action: fresh Sovereign Coolify install."
            ;;
        coolify)
            term_line "${C_YELLOW}   Detected: existing Coolify state${C_RESET}"
            note "Recommended action: preserve data, upgrade compose/image, generate one-time SL1 admin claim."
            ;;
        sovereign)
            term_line "${C_CYAN}   Detected: existing Sovereign Coolify state${C_RESET}"
            note "Recommended action: refresh compose/image and restart containers."
            ;;
    esac
    echo ""
}

choose_auto_mode() {
    local state="$1"
    local choice

    print_detection "$state"

    if ! can_prompt; then
        case "$state" in
            fresh)
                SELECTED_INSTALL_MODE="fresh"
                SELECTED_ADMIN_CLAIM="false"
                ;;
            coolify)
                SELECTED_INSTALL_MODE="upgrade"
                SELECTED_ADMIN_CLAIM="true"
                ;;
            sovereign)
                SELECTED_INSTALL_MODE="refresh"
                SELECTED_ADMIN_CLAIM="${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE:-false}"
                ;;
        esac
        note "No interactive terminal detected. Auto-selected: ${SELECTED_INSTALL_MODE}."
        echo ""
        return
    fi

    case "$state" in
        fresh)
            term_line "${C_MAGENTA}[1]${C_RESET} Fresh install Sovereign Coolify"
            note "Creates /data/coolify, Postgres, Redis, SL1-only auth, cyberpunk UI."
            term_line "${C_MAGENTA}[q]${C_RESET} Abort"
            while true; do
                printf 'Select action [1/q]: ' > /dev/tty
                read -r choice < /dev/tty
                case "$choice" in
                    1|"")
                        SELECTED_INSTALL_MODE="fresh"
                        SELECTED_ADMIN_CLAIM="false"
                        break
                        ;;
                    q|Q)
                        die "Aborted by operator."
                        ;;
                esac
            done
            ;;
        coolify)
            term_line "${C_MAGENTA}[1]${C_RESET} Upgrade existing Coolify to Sovereign + generate admin claim"
            note "Preserves database/volumes, installs SL1-only auth, prints CLAIM_URL for the current admin."
            term_line "${C_MAGENTA}[2]${C_RESET} Refresh compose/image only, no admin claim"
            note "Use this if the admin was already claimed or you only want the newest cyberpunk build."
            term_line "${C_MAGENTA}[q]${C_RESET} Abort"
            while true; do
                printf 'Select action [1/2/q]: ' > /dev/tty
                read -r choice < /dev/tty
                case "$choice" in
                    1|"")
                        SELECTED_INSTALL_MODE="upgrade"
                        SELECTED_ADMIN_CLAIM="true"
                        break
                        ;;
                    2)
                        SELECTED_INSTALL_MODE="refresh"
                        SELECTED_ADMIN_CLAIM="false"
                        break
                        ;;
                    q|Q)
                        die "Aborted by operator."
                        ;;
                esac
            done
            ;;
        sovereign)
            term_line "${C_MAGENTA}[1]${C_RESET} Refresh Sovereign Coolify"
            note "Pulls the latest ghcr.io image, updates compose/env defaults, restarts containers."
            term_line "${C_MAGENTA}[2]${C_RESET} Refresh and generate a new admin claim"
            note "Use this only if an existing admin still needs to bind SL1 Identity."
            term_line "${C_MAGENTA}[q]${C_RESET} Abort"
            while true; do
                printf 'Select action [1/2/q]: ' > /dev/tty
                read -r choice < /dev/tty
                case "$choice" in
                    1|"")
                        SELECTED_INSTALL_MODE="refresh"
                        SELECTED_ADMIN_CLAIM="false"
                        break
                        ;;
                    2)
                        SELECTED_INSTALL_MODE="upgrade"
                        SELECTED_ADMIN_CLAIM="true"
                        break
                        ;;
                    q|Q)
                        die "Aborted by operator."
                        ;;
                esac
            done
            ;;
    esac

    echo ""
}

resolve_install_mode() {
    local state="$1"

    case "$SOVEREIGN_INSTALL_MODE" in
        auto)
            choose_auto_mode "$state"
            ;;
        fresh)
            SELECTED_INSTALL_MODE="fresh"
            SELECTED_ADMIN_CLAIM="false"
            ;;
        upgrade)
            SELECTED_INSTALL_MODE="upgrade"
            SELECTED_ADMIN_CLAIM="${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE:-true}"
            ;;
        refresh)
            SELECTED_INSTALL_MODE="refresh"
            SELECTED_ADMIN_CLAIM="${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE:-false}"
            ;;
        *)
            die "Invalid SOVEREIGN_INSTALL_MODE=${SOVEREIGN_INSTALL_MODE}. Use auto, fresh, upgrade, or refresh."
            ;;
    esac

    if [ "$SELECTED_INSTALL_MODE" = "fresh" ] && [ "$state" != "fresh" ]; then
        die "Fresh install was requested, but existing Coolify state was detected. Refusing to protect volumes and database."
    fi

    if { [ "$SELECTED_INSTALL_MODE" = "upgrade" ] || [ "$SELECTED_INSTALL_MODE" = "refresh" ]; } && [ "$state" = "fresh" ]; then
        die "${SELECTED_INSTALL_MODE} was requested, but no existing Coolify installation was detected."
    fi
}

choose_host_domain() {
    local existing_app_url public_ip domain_choice continue_choice

    existing_app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"

    if [ -n "$SELECTED_HOST_DOMAIN" ]; then
        SELECTED_APP_URL="${SOVEREIGN_APP_SCHEME}://${SELECTED_HOST_DOMAIN}"
    elif can_prompt; then
        section "Canonical host domain"
        if [ -n "$existing_app_url" ]; then
            note "Current APP_URL: ${existing_app_url}"
        fi
        note "Use a real domain for TLS and SL1 callback correctness."
        printf 'Panel domain (blank to keep current or use IP fallback): ' > /dev/tty
        read -r domain_choice < /dev/tty
        if [ -n "$domain_choice" ]; then
            SELECTED_HOST_DOMAIN="$domain_choice"
            SELECTED_APP_URL="${SOVEREIGN_APP_SCHEME}://${domain_choice}"
        elif [ -n "$existing_app_url" ] && [ "$existing_app_url" != "http://localhost" ] && [ "$existing_app_url" != "https://localhost" ]; then
            SELECTED_APP_URL="$existing_app_url"
        else
            SELECTED_APP_URL="http://$(hostname -I 2>/dev/null | awk '{print $1}'):${APP_PORT}"
        fi
    elif [ -n "$existing_app_url" ] && [ "$existing_app_url" != "http://localhost" ] && [ "$existing_app_url" != "https://localhost" ]; then
        SELECTED_APP_URL="$existing_app_url"
    else
        SELECTED_APP_URL="http://$(hostname -I 2>/dev/null | awk '{print $1}'):${APP_PORT}"
    fi

    if [ -n "$SELECTED_HOST_DOMAIN" ]; then
        public_ip="$(detect_public_ip)"
        if [ -n "$public_ip" ]; then
            note "Detected public IP: ${public_ip}"
            if domain_points_to_host "$SELECTED_HOST_DOMAIN" "$public_ip"; then
                term_line "${C_GREEN}   DNS check passed for ${SELECTED_HOST_DOMAIN}.${C_RESET}"
            else
                warning "DNS for ${SELECTED_HOST_DOMAIN} does not appear to resolve to ${public_ip} yet."
                if can_prompt; then
                    printf 'Continue anyway? [y/N]: ' > /dev/tty
                    read -r continue_choice < /dev/tty
                    case "$continue_choice" in
                        y|Y|yes|YES) ;;
                        *) die "Aborted until DNS is bound to this host." ;;
                    esac
                fi
            fi
        fi
    fi
}

apply_host_domain_env() {
    if [ -z "$SELECTED_APP_URL" ]; then
        return 0
    fi

    set_env_var "APP_URL" "$SELECTED_APP_URL"
    if [ -n "$SELECTED_HOST_DOMAIN" ]; then
        set_env_var "SOVEREIGN_HOST_DOMAIN" "$SELECTED_HOST_DOMAIN"
        local public_ip
        public_ip="$(detect_public_ip)"
        if [ -n "$public_ip" ]; then
            set_env_var "SOVEREIGN_HOST_PUBLIC_IP" "$public_ip"
        fi
    fi
}

choose_smtp_mode() {
    local mode_choice

    if [ -n "${SOVEREIGN_SMTP_MODE:-}" ] && [ "$SOVEREIGN_SMTP_MODE" != "auto" ]; then
        return 0
    fi

    if ! can_prompt; then
        if [ "${SOVEREIGN_SMTP_MODE:-}" = "auto" ]; then
            export SOVEREIGN_SMTP_MODE="none"
        fi
        return 0
    fi

    section "Host SMTP"
    note "Default is none. Relay mode configures outbound Postfix without a public open relay."
    term_line "${C_MAGENTA}[1]${C_RESET} none"
    term_line "${C_MAGENTA}[2]${C_RESET} relay through smarthost"
    term_line "${C_MAGENTA}[3]${C_RESET} direct outbound MTA"
    printf 'Select SMTP mode [1/2/3]: ' > /dev/tty
    read -r mode_choice < /dev/tty
    case "$mode_choice" in
        2)
            export SOVEREIGN_SMTP_MODE="relay"
            printf 'Relay host: ' > /dev/tty
            read -r SOVEREIGN_SMTP_RELAY_HOST < /dev/tty
            export SOVEREIGN_SMTP_RELAY_HOST
            printf 'Relay port [587]: ' > /dev/tty
            read -r SOVEREIGN_SMTP_RELAY_PORT < /dev/tty
            export SOVEREIGN_SMTP_RELAY_PORT="${SOVEREIGN_SMTP_RELAY_PORT:-587}"
            printf 'Relay username (blank if none): ' > /dev/tty
            read -r SOVEREIGN_SMTP_RELAY_USERNAME < /dev/tty
            export SOVEREIGN_SMTP_RELAY_USERNAME
            printf 'Relay password (blank if none): ' > /dev/tty
            read -rs SOVEREIGN_SMTP_RELAY_PASSWORD < /dev/tty
            printf '\n' > /dev/tty
            export SOVEREIGN_SMTP_RELAY_PASSWORD
            ;;
        3)
            warning "Direct SMTP requires PTR/rDNS, SPF, DKIM, DMARC, and provider support for outbound port 25."
            export SOVEREIGN_SMTP_MODE="direct"
            ;;
        *)
            export SOVEREIGN_SMTP_MODE="none"
            ;;
    esac
}

choose_hardening() {
    local hardening_choice

    case "$SOVEREIGN_HARDENING" in
        true|1|yes|YES)
            SELECTED_HARDENING="true"
            ;;
        false|0|no|NO)
            SELECTED_HARDENING="false"
            ;;
        auto)
            if can_prompt; then
                section "Host hardening"
                note "Applies reversible baseline: UFW, Fail2Ban, sysctl, Docker logs, optional SMTP relay."
                printf 'Apply Sovereign host hardening now? [y/N]: ' > /dev/tty
                read -r hardening_choice < /dev/tty
                case "$hardening_choice" in
                    y|Y|yes|YES) SELECTED_HARDENING="true" ;;
                    *) SELECTED_HARDENING="false" ;;
                esac
            else
                SELECTED_HARDENING="false"
            fi
            ;;
        *)
            die "Invalid SOVEREIGN_HARDENING=${SOVEREIGN_HARDENING}. Use auto, true, or false."
            ;;
    esac

    if [ "$SELECTED_HARDENING" = "true" ]; then
        choose_smtp_mode
    fi
}

run_host_hardening() {
    if [ "$SELECTED_HARDENING" != "true" ]; then
        return 0
    fi

    download_file scripts/sovereign-host-hardening.sh "${SOURCE_DIR}/sovereign-host-hardening.sh"
    chmod +x "${SOURCE_DIR}/sovereign-host-hardening.sh"

    local hardening_app_port hardening_soketi_port
    hardening_app_port="$(strip_env_quotes "$(get_env_var APP_PORT)")"
    hardening_soketi_port="$(strip_env_quotes "$(get_env_var SOKETI_PORT)")"

    APP_PORT="${hardening_app_port:-$APP_PORT}" \
    SOKETI_PORT="${hardening_soketi_port:-$SOKETI_PORT}" \
    SOVEREIGN_HARDENING_PROFILE="$SOVEREIGN_HARDENING_PROFILE" \
    SOVEREIGN_HOST_DOMAIN="$SELECTED_HOST_DOMAIN" \
    SOVEREIGN_HOST_PUBLIC_IP="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_PUBLIC_IP)")" \
    bash "${SOURCE_DIR}/sovereign-host-hardening.sh"
}

run_existing_upgrade() {
    local generate_claim="${1:-false}"

    log "Existing Coolify installation detected. Running sovereign ${SELECTED_INSTALL_MODE} path."
    download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
    chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"

    SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE="$generate_claim" bash "${SOURCE_DIR}/upgrade-sovereign.sh"

    echo ""
    term_line "${C_GREEN}Sovereign Coolify ${SELECTED_INSTALL_MODE} complete.${C_RESET}"
    if [ "$generate_claim" = "true" ]; then
        echo "Use the printed CLAIM_URL to bind the existing admin to SimpleL1 Identity."
    else
        echo "No admin claim was requested."
    fi
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
    die "Please run this script as root or with sudo."
fi

mkdir -p "${SOURCE_DIR}" "${INSTALL_ROOT}"/{ssh,applications,databases,backups,services,proxy,sentinel}
mkdir -p "${INSTALL_ROOT}/ssh/keys" "${INSTALL_ROOT}/ssh/mux" "${INSTALL_ROOT}/proxy/dynamic"
touch "$LOG_FILE"

banner

check_host_resources
install_docker
login_to_registry

DETECTED_INSTALL_STATE="$(detected_install_state)"
resolve_install_mode "$DETECTED_INSTALL_STATE"
choose_host_domain
choose_hardening

section "Selected action"
note "Mode:        ${SELECTED_INSTALL_MODE}"
note "Admin claim: ${SELECTED_ADMIN_CLAIM}"
note "Hardening:   ${SELECTED_HARDENING}"
if [ -n "$SELECTED_APP_URL" ]; then
    note "APP_URL:     ${SELECTED_APP_URL}"
fi
echo ""

case "$SELECTED_INSTALL_MODE" in
    upgrade|refresh)
        apply_host_domain_env
        run_host_hardening
        run_existing_upgrade "$SELECTED_ADMIN_CLAIM"
        exit 0
        ;;
    fresh)
        ;;
    *)
        die "Invalid selected install mode: ${SELECTED_INSTALL_MODE}"
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
apply_host_domain_env

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
set_env_var "SOVEREIGN_HARDENING_PROFILE" "$SOVEREIGN_HARDENING_PROFILE"
if [ -n "${SOVEREIGN_WIREGUARD_CIDRS:-}" ]; then
    set_env_var "SOVEREIGN_WIREGUARD_CIDRS" "$SOVEREIGN_WIREGUARD_CIDRS"
fi

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
run_host_hardening
chown -R 9999:root "$INSTALL_ROOT"
chmod -R 700 "$INSTALL_ROOT"

log "Starting Sovereign Coolify"
bash "${SOURCE_DIR}/upgrade-sovereign.sh"

echo ""
echo "Sovereign Coolify installation complete."
echo "Open: http://$(hostname -I | awk '{print $1}'):${APP_PORT}"
echo "Logs: ${LOG_FILE}"
