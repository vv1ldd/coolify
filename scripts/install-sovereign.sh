#!/usr/bin/env bash

set -euo pipefail

DATE="$(date +"%Y%m%d-%H%M%S")"
INSTALL_ROOT="${COOLIFY_INSTALL_ROOT:-/data/coolify}"
SOURCE_DIR="${COOLIFY_SOURCE_DIR:-${INSTALL_ROOT}/source}"
ENV_FILE="${SOURCE_DIR}/.env"
STATUS_FILE="${SOURCE_DIR}/.install-sovereign-status"
LOG_FILE="${SOURCE_DIR}/installation-sovereign-${DATE}.log"
REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
RAW_BASE="${SOVEREIGN_RAW_BASE:-https://raw.githubusercontent.com/${REPOSITORY}/${BRANCH}}"
COOLIFY_IMAGE="${COOLIFY_IMAGE:-ghcr.io/${REPOSITORY}:sovereign}"
SOVEREIGN_REALTIME_IMAGE="${SOVEREIGN_REALTIME_IMAGE:-ghcr.io/coollabsio/coolify-realtime:1.0.13}"
SIMPLE_L1_IMAGE="${SIMPLE_L1_IMAGE:-ghcr.io/vv1ldd/simple-l1:latest}"
HELPER_IMAGE="${HELPER_IMAGE:-ghcr.io/coollabsio/coolify-helper}"
SOVEREIGN_RUN_ID="${SOVEREIGN_RUN_ID:-$DATE}"
SOVEREIGN_EXPECTED_RESULT="${SOVEREIGN_EXPECTED_RESULT:-sovereign-coolify-runtime-converged}"
APP_PORT="${APP_PORT:-8000}"
SOKETI_PORT="${SOKETI_PORT:-6001}"
AUTOUPDATE="${AUTOUPDATE:-false}"
SOVEREIGN_INSTALL_MODE="${SOVEREIGN_INSTALL_MODE:-auto}"
SOVEREIGN_HARDENING="${SOVEREIGN_HARDENING:-auto}"
SOVEREIGN_HARDENING_PROFILE="${SOVEREIGN_HARDENING_PROFILE:-baseline}"
SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"
SOVEREIGN_HOST_DOMAIN="${SOVEREIGN_HOST_DOMAIN:-}"
SOVEREIGN_IDENTITY_DOMAIN="${SOVEREIGN_IDENTITY_DOMAIN:-}"
SOVEREIGN_RP_ID="${SOVEREIGN_RP_ID:-}"
SOVEREIGN_REQUIRE_HOST_DOMAIN="${SOVEREIGN_REQUIRE_HOST_DOMAIN:-true}"
SOVEREIGN_REQUIRE_CLOUDFLARE_TOKEN="${SOVEREIGN_REQUIRE_CLOUDFLARE_TOKEN:-true}"
SOVEREIGN_HOST_PROFILE="${SOVEREIGN_HOST_PROFILE:-}"
SOVEREIGN_TUNNEL_NAME="${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
SOVEREIGN_ALLOW_DIRECT_APP_PORT="${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-}"
SOVEREIGN_RUNTIME_CONVERGE_OWNER="${SOVEREIGN_RUNTIME_CONVERGE_OWNER:-false}"
SOVEREIGN_VERBOSE="${SOVEREIGN_VERBOSE:-false}"
SL1_CONNECT_ISSUER="${SL1_CONNECT_ISSUER:-https://simplel1.online}"
SL1_CONNECT_CLIENT_ID="${SL1_CONNECT_CLIENT_ID:-coolify.sovereign}"
SL1_CONNECT_CLIENT_NAME="${SL1_CONNECT_CLIENT_NAME:-Sovereign-Coolify}"
SL1_CONNECT_CALLBACK_PATH="${SL1_CONNECT_CALLBACK_PATH:-/auth/sl1/callback}"
SL1_CONNECT_TIMEOUT="${SL1_CONNECT_TIMEOUT:-10}"
SIMPLE_L1_DOMAIN="${SIMPLE_L1_DOMAIN:-simplel1.online}"
SIMPLE_L1_ISSUER_URL="${SIMPLE_L1_ISSUER_URL:-https://simplel1.online/sl1}"
SIMPLE_L1_NODE_NAME="${SIMPLE_L1_NODE_NAME:-sovereign-coolify-node}"
SIMPLE_L1_NETWORK_NAME="${SIMPLE_L1_NETWORK_NAME:-Simple-L1}"
SIMPLE_L1_NODE_TYPE_LABEL="${SIMPLE_L1_NODE_TYPE_LABEL:-Sovereign Coolify Node}"
SIMPLE_L1_SELF_WEBHOOK="${SIMPLE_L1_SELF_WEBHOOK:-}"
SIMPLE_L1_PEERS="${SIMPLE_L1_PEERS:-}"
SIMPLE_L1_STORAGE_ROLE="${SIMPLE_L1_STORAGE_ROLE:-cache}"
SIMPLE_L1_IDENTITY_PROTOCOL_VERSION="${SIMPLE_L1_IDENTITY_PROTOCOL_VERSION:-capsule-v0}"
SIMPLE_L1_IDENTITY_CAPSULES_ENABLED="${SIMPLE_L1_IDENTITY_CAPSULES_ENABLED:-true}"
SIMPLE_L1_EVIDENCE_RESOLVERS="${SIMPLE_L1_EVIDENCE_RESOLVERS:-local-cache,client-capsule,peer,signed-export}"
SIMPLE_L1_STATE_RESOLVERS="${SIMPLE_L1_STATE_RESOLVERS:-local-cache,peer,anchor,quorum,signed-export}"
SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL="${SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL:-AL1}"
SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE="${SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE:-false}"
SIMPLE_L1_DNS_TTL="${SIMPLE_L1_DNS_TTL:-60}"
SIMPLE_L1_DNS_STEERING_ENABLED="${SIMPLE_L1_DNS_STEERING_ENABLED:-false}"
SIMPLE_L1_CLOUDFLARE_PROXIED="${SIMPLE_L1_CLOUDFLARE_PROXIED:-false}"
SIMPLE_L1_CLOUDFLARE_API_TOKEN="${SIMPLE_L1_CLOUDFLARE_API_TOKEN:-}"
SIMPLE_L1_CLOUDFLARE_ZONE_ID="${SIMPLE_L1_CLOUDFLARE_ZONE_ID:-}"
SIMPLE_L1_PUBLIC_IP="${SIMPLE_L1_PUBLIC_IP:-}"
SIMPLE_L1_FAILOVER_NODES="${SIMPLE_L1_FAILOVER_NODES:-}"
DIGITAL_GOODS_SOURCE_ENABLED="${DIGITAL_GOODS_SOURCE_ENABLED:-true}"
DIGITAL_GOODS_SOURCE_URL="${DIGITAL_GOODS_SOURCE_URL:-http://digital-goods-source:8080}"
DIGITAL_GOODS_SOURCE_STATUS_URLS="${DIGITAL_GOODS_SOURCE_STATUS_URLS:-http://digital-goods-source:8080,http://127.0.0.1:8091}"
DIGITAL_GOODS_SOURCE_IMAGE="${DIGITAL_GOODS_SOURCE_IMAGE:-ghcr.io/vv1ldd/digital-goods-source:latest}"
DIGITAL_GOODS_SOURCE_PORT="${DIGITAL_GOODS_SOURCE_PORT:-8091}"
DIGITAL_GOODS_SOURCE_RUNTIME_VERSION="${DIGITAL_GOODS_SOURCE_RUNTIME_VERSION:-1.0.0}"
DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION="${DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION:-v1}"
DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION="${DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION:-v1}"
DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN="${DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN:-}"
DIGITAL_GOODS_SOURCE_FINANCIAL_SECRET="${DIGITAL_GOODS_SOURCE_FINANCIAL_SECRET:-}"
DIGITAL_GOODS_SOURCE_SIGNATURE_TOLERANCE="${DIGITAL_GOODS_SOURCE_SIGNATURE_TOLERANCE:-300}"
DIGITAL_GOODS_SOURCE_DB_DATABASE="${DIGITAL_GOODS_SOURCE_DB_DATABASE:-/data/digital-goods-source.sqlite}"
SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND="${SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND:-false}"
SOVEREIGN_DNS_STEERING_SCHEDULE="${SOVEREIGN_DNS_STEERING_SCHEDULE:-off}"
SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_CONFIGURED="${SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE+x}"
SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="${SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE:-off}"
SOVEREIGN_SIMPLE_L1_BOOTSTRAP="${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-auto}"
SOVEREIGN_DISK_ENCRYPT="${SOVEREIGN_DISK_ENCRYPT:-false}"
SOVEREIGN_DISK_SCRIPT_DIR="${SOVEREIGN_DISK_SCRIPT_DIR:-/opt/sovereign/disk}"
SELECTED_INSTALL_MODE=""
SELECTED_ADMIN_CLAIM="false"
SELECTED_HARDENING="false"
SELECTED_APP_URL=""
SELECTED_HOST_DOMAIN="$SOVEREIGN_HOST_DOMAIN"
SELECTED_HOST_PROFILE=""
SELECTED_SIMPLE_L1_CLOUDFLARE="false"
_INSTALL_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || pwd)"
if [ -z "${SOVEREIGN_LOCAL_REPO_PATH:-}" ] && [ -f "${_INSTALL_SCRIPT_DIR}/../docker-compose.yml" ]; then
    SOVEREIGN_LOCAL_REPO_PATH="$(cd "${_INSTALL_SCRIPT_DIR}/.." && pwd)"
fi

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
    term_line "${C_MAGENTA}╔════════════════════════════════════════════════════════════╗${C_RESET}"
    term_line "${C_CYAN}  SOVEREIGN COOLIFY // SL1 CONTROL-PLANE BOOTSTRAP${C_RESET}"
    term_line "${C_MAGENTA}╚════════════════════════════════════════════════════════════╝${C_RESET}"
    term_line "${C_DIM}  identity: SL1-only  theme: cyberpunk  mode: auto-aware${C_RESET}"
    echo ""
}

section() {
    term_line "${C_MAGENTA}▸${C_RESET} ${C_CYAN}$*${C_RESET}"
}

note() {
    term_line "${C_DIM}   $*${C_RESET}"
}

warning() {
    term_line "${C_YELLOW}!${C_RESET} $*"
}

progress() {
    local current="$1"
    local total="$2"
    local label="$3"
    local width=24
    local filled empty percent bar=""

    if [ "${total}" -le 0 ]; then
        total=1
    fi
    percent=$((current * 100 / total))
    filled=$((current * width / total))
    empty=$((width - filled))

    while [ "${filled}" -gt 0 ]; do
        bar="${bar}█"
        filled=$((filled - 1))
    done
    while [ "${empty}" -gt 0 ]; do
        bar="${bar}░"
        empty=$((empty - 1))
    done

    term_line "${C_MAGENTA}[${bar}]${C_RESET} ${C_CYAN}${current}/${total}${C_RESET} ${C_GREEN}${percent}%${C_RESET} ${label}"
}

fingerprint() {
    local seed="$1"

    if command -v sha256sum >/dev/null 2>&1; then
        printf '%s' "$seed" | sha256sum | awk '{ print substr($1, 1, 12) }'
        return
    fi

    if command -v shasum >/dev/null 2>&1; then
        printf '%s' "$seed" | shasum -a 256 | awk '{ print substr($1, 1, 12) }'
        return
    fi

    printf '%s' "$seed" | cksum | awk '{ printf "%012x", $1 }'
}

checkpoint() {
    local current="$1"
    local total="$2"
    local code="$3"
    local message="$4"
    local detail="${5:-}"
    local percent fp timestamp

    if [ "${total}" -le 0 ]; then
        total=1
    fi

    percent=$((current * 100 / total))
    timestamp="$(date -Iseconds)"
    fp="$(fingerprint "${SOVEREIGN_RUN_ID}|${SOVEREIGN_EXPECTED_RESULT}|${current}/${total}|${code}|${message}|${REPOSITORY}|${BRANCH}")"

    printf '%s|%s|%s|%s|%s|%s|%s|%s|%s\n' "${SOVEREIGN_RUN_ID}" "${current}" "${total}" "${percent}" "${code}" "${fp}" "${SOVEREIGN_EXPECTED_RESULT}" "${message}" "${timestamp}" > "$STATUS_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] run=${SOVEREIGN_RUN_ID} checkpoint=${code} progress=${percent}% fingerprint=${fp} expected=${SOVEREIGN_EXPECTED_RESULT} ${message} ${detail}" >> "$LOG_FILE"
    term_line "${C_GREEN}✓${C_RESET} ${C_CYAN}${percent}%${C_RESET} ${code} ${C_DIM}fp:${fp}${C_RESET} ${message}"
    if [ -n "$detail" ]; then
        note "$detail"
    fi
}

final_fingerprint() {
    fingerprint "${SOVEREIGN_RUN_ID}|${SOVEREIGN_EXPECTED_RESULT}|4/4|RUNTIME_CONVERGED|Sovereign runtime converge finished|${REPOSITORY}|${BRANCH}"
}

progress_contract() {
    section "Install contract"
    note "Run:      ${SOVEREIGN_RUN_ID}"
    note "Target:   ${SOVEREIGN_EXPECTED_RESULT}"
    note "Final fp: $(final_fingerprint)"
    note "Status:   ${STATUS_FILE}"
    note "Format:   ✓ <percent> <checkpoint> fp:<fingerprint> <result>"
    echo ""
}

die() {
    term_line "${C_RED}ERROR: $*${C_RESET}"
    exit 1
}

require_runtime_converge_owner() {
    if [ "${SOVEREIGN_RUNTIME_CONVERGE_OWNER}" != "true" ]; then
        term_line "[runtime] converge owner = external, skipping mutations"
        exit 0
    fi

    term_line "[runtime] converge owner = runtime, executing mutations"
}

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

show_log_tail() {
    warning "Command failed. Last log lines from ${LOG_FILE}:"
    tail -n 80 "$LOG_FILE" 2>/dev/null || true
}

run_logged() {
    local label="$1"
    shift
    local frames=('⠋' '⠙' '⠹' '⠸' '⠼' '⠴' '⠦' '⠧' '⠇' '⠏')
    local frame_index=0 pid rc

    if [ "${SOVEREIGN_VERBOSE}" = "true" ]; then
        "$@"
        return $?
    fi

    "$@" >> "$LOG_FILE" 2>&1 &
    pid=$!
    while kill -0 "$pid" 2>/dev/null; do
        if [ -t 1 ]; then
            printf '\r%b %s...' "${C_MAGENTA}${frames[$frame_index]}${C_RESET}" "${label}"
        fi
        frame_index=$(((frame_index + 1) % ${#frames[@]}))
        sleep 0.15
    done
    set +e
    wait "$pid"
    rc=$?
    set -e

    if [ -t 1 ]; then
        printf '\r\033[K'
    fi

    if [ "${rc}" -eq 0 ]; then
        term_line "${C_GREEN}✓${C_RESET} ${label}"
        return 0
    fi

    {
        show_log_tail
        return "${rc}"
    }
}

download_file() {
    local source_path="$1"
    local target_path="$2"

    if [ -n "${SOVEREIGN_LOCAL_REPO_PATH:-}" ] && [ -f "${SOVEREIGN_LOCAL_REPO_PATH}/${source_path}" ]; then
        log "Using local repo file ${source_path}"
        cp "${SOVEREIGN_LOCAL_REPO_PATH}/${source_path}" "$target_path"
        return 0
    fi

    log "Downloading ${source_path}"
    curl -fsSL "${RAW_BASE}/${source_path}" -o "$target_path"
}

ensure_sovereign_disk_scripts() {
    local script_name script_dir="${SOVEREIGN_DISK_SCRIPT_DIR}"
    local scripts=(
        sovereign-disk-lib.sh
        sovereign-disk-gate.sh
        sovereign-disk-network.sh
        sovereign-disk-kexec-autoinstall.sh
        sovereign-disk-prepare.sh
        sovereign-firstboot.sh
    )

    mkdir -p "$script_dir"

    for script_name in "${scripts[@]}"; do
        if [ -f "${_INSTALL_SCRIPT_DIR}/sovereign-disk/${script_name}" ]; then
            cp "${_INSTALL_SCRIPT_DIR}/sovereign-disk/${script_name}" "${script_dir}/${script_name}"
        elif [ -n "${SOVEREIGN_LOCAL_REPO_PATH:-}" ] && [ -f "${SOVEREIGN_LOCAL_REPO_PATH}/scripts/sovereign-disk/${script_name}" ]; then
            cp "${SOVEREIGN_LOCAL_REPO_PATH}/scripts/sovereign-disk/${script_name}" "${script_dir}/${script_name}"
        else
            download_file "scripts/sovereign-disk/${script_name}" "${script_dir}/${script_name}"
        fi
        chmod +x "${script_dir}/${script_name}" 2>/dev/null || true
    done

    export SOVEREIGN_DISK_SCRIPT_DIR="$script_dir"
}

run_sovereign_disk_gate() {
    case "${SOVEREIGN_DISK_ENCRYPT:-false}" in
        true|auto|1|yes|reboot)
            ;;
        *)
            return 0
            ;;
    esac

    ensure_sovereign_disk_scripts
    # shellcheck source=/dev/null
    source "${SOVEREIGN_DISK_SCRIPT_DIR}/sovereign-disk-gate.sh"
    sovereign_disk_gate
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

host_from_url() {
    local value="$1"
    value="${value#*://}"
    value="${value%%/*}"
    value="${value%%:*}"
    printf '%s' "$value"
}

load_sovereign_identity_env_helpers() {
    local candidate

    if [ -n "${SOVEREIGN_IDENTITY_ENV_SH:-}" ] && [ -f "$SOVEREIGN_IDENTITY_ENV_SH" ]; then
        # shellcheck source=scripts/sovereign-identity-env.sh
        . "$SOVEREIGN_IDENTITY_ENV_SH"
        return 0
    fi

    for candidate in \
        "$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)/sovereign-identity-env.sh" \
        "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"; do
        if [ -f "$candidate" ]; then
            # shellcheck source=scripts/sovereign-identity-env.sh
            . "$candidate"
            return 0
        fi
    done

    mkdir -p "${SOURCE_DIR}/scripts"
    if download_file scripts/sovereign-identity-env.sh "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"; then
        # shellcheck source=scripts/sovereign-identity-env.sh
        . "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"
        return 0
    fi

    die "Could not load sovereign-identity-env helpers."
}

load_sovereign_host_profile_helpers() {
    local candidate

    for candidate in \
        "$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)/sovereign-host-profile.sh" \
        "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"; do
        if [ -f "$candidate" ]; then
            # shellcheck source=scripts/sovereign-host-profile.sh
            . "$candidate"
            return 0
        fi
    done

    mkdir -p "${SOURCE_DIR}/scripts"
    if download_file scripts/sovereign-host-profile.sh "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"; then
        # shellcheck source=scripts/sovereign-host-profile.sh
        . "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"
        return 0
    fi

    die "Could not load sovereign-host-profile helpers."
}

choose_host_profile() {
    local choice normalized

    if [ "$(uname -s)" = "Darwin" ] && [ "${SOVEREIGN_ALLOW_MAC_INSTALL:-false}" != "true" ]; then
        die "Sovereign Coolify install targets Linux VPS only. On macOS use marketplace/scripts/dev-tunnel.sh for local dev, or set SOVEREIGN_ALLOW_MAC_INSTALL=true to override."
    fi

    if [ -n "${SOVEREIGN_HOST_PROFILE:-}" ]; then
        normalized="$(normalize_host_profile "$SOVEREIGN_HOST_PROFILE")" || die "Invalid SOVEREIGN_HOST_PROFILE=${SOVEREIGN_HOST_PROFILE}. Use linux-vps."
        if [ "$normalized" = "mac-dev" ] && [ "${SOVEREIGN_ALLOW_MAC_INSTALL:-false}" != "true" ]; then
            die "mac-dev profile is deprecated. Use marketplace dev-tunnel for Mac, or set SOVEREIGN_ALLOW_MAC_INSTALL=true."
        fi
        SELECTED_HOST_PROFILE="$normalized"
        apply_host_profile_shell_defaults
        note "Host profile: ${SELECTED_HOST_PROFILE} (from environment)."
        return 0
    fi

    SELECTED_HOST_PROFILE="linux-vps"
    apply_host_profile_shell_defaults
    note "Host profile: linux-vps (VPS production install)."
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

can_prompt_cloudflare() {
    [ -r /dev/tty ] && [ -w /dev/tty ]
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
            note "Recommended action: choose [1] to pull the latest Sovereign image and restart safely."
            ;;
    esac
    note "Non-interactive: SOVEREIGN_RUNTIME_CONVERGE_OWNER=true SOVEREIGN_INSTALL_MODE=refresh SOVEREIGN_ASSUME_YES=true bash"
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
            term_line "${C_MAGENTA}[1]${C_RESET} Update this Sovereign Coolify now ${C_GREEN}(recommended)${C_RESET}"
            note "Pulls the latest image, keeps your data, updates runtime files, and restarts containers."
            term_line "${C_MAGENTA}[2]${C_RESET} Update and print a new admin SL1 claim link"
            note "Choose this only if an existing admin still needs to bind their SL1 Identity."
            term_line "${C_MAGENTA}[q]${C_RESET} Abort"
            while true; do
                printf 'What should I do? [1 = update, 2 = update + claim, q = abort]: ' > /dev/tty
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
    local existing_app_url public_ip domain_choice identity_choice continue_choice

    existing_app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"

    if [ -n "$SELECTED_HOST_DOMAIN" ]; then
        SELECTED_APP_URL="${SOVEREIGN_APP_SCHEME}://${SELECTED_HOST_DOMAIN}"
    elif can_prompt; then
        section "Canonical host domain"
        if [ -n "$existing_app_url" ]; then
            note "Current APP_URL: ${existing_app_url}"
        fi
        note "Use a real domain for TLS, SL1 Connect, and Coolify proxy."
        note "Panel domain and Simple L1 identity domain can differ (for example ops.example.com + identity.example.com)."
        printf 'Coolify panel domain (for example ops.meanly.one): ' > /dev/tty
        read -r domain_choice < /dev/tty
        domain_choice="$(printf '%s' "$domain_choice" | tr '[:upper:]' '[:lower:]')"
        domain_choice="${domain_choice#"${domain_choice%%[![:space:]]*}"}"
        domain_choice="${domain_choice%"${domain_choice##*[![:space:]]}"}"
        if [ -n "$domain_choice" ]; then
            SELECTED_HOST_DOMAIN="$domain_choice"
            SELECTED_APP_URL="${SOVEREIGN_APP_SCHEME}://${domain_choice}"
        elif [ -n "$existing_app_url" ] && [ "$existing_app_url" != "http://localhost" ] && [ "$existing_app_url" != "https://localhost" ]; then
            SELECTED_APP_URL="$existing_app_url"
            SELECTED_HOST_DOMAIN="$(host_from_url "$existing_app_url")"
        elif [ "$SELECTED_INSTALL_MODE" = "fresh" ] && [ "${SOVEREIGN_REQUIRE_HOST_DOMAIN:-true}" = "true" ]; then
            die "Fresh Sovereign install requires a public panel domain. Set SOVEREIGN_HOST_DOMAIN or rerun interactively."
        else
            SELECTED_APP_URL="http://$(hostname -I 2>/dev/null | awk '{print $1}'):${APP_PORT}"
        fi

        if [ -z "${SOVEREIGN_IDENTITY_DOMAIN:-}" ] && [ -n "$SELECTED_HOST_DOMAIN" ]; then
            printf 'Simple L1 identity domain [%s]: ' "$SELECTED_HOST_DOMAIN" > /dev/tty
            read -r identity_choice < /dev/tty
            identity_choice="$(printf '%s' "$identity_choice" | tr '[:upper:]' '[:lower:]')"
            identity_choice="${identity_choice#"${identity_choice%%[![:space:]]*}"}"
            identity_choice="${identity_choice%"${identity_choice##*[![:space:]]}"}"
            if [ -n "$identity_choice" ]; then
                SOVEREIGN_IDENTITY_DOMAIN="$identity_choice"
            fi
        fi
    elif [ -n "$existing_app_url" ] && [ "$existing_app_url" != "http://localhost" ] && [ "$existing_app_url" != "https://localhost" ]; then
        SELECTED_APP_URL="$existing_app_url"
        SELECTED_HOST_DOMAIN="$(host_from_url "$existing_app_url")"
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
            if [ -n "${SOVEREIGN_IDENTITY_DOMAIN:-}" ] \
                && [ "$SOVEREIGN_IDENTITY_DOMAIN" != "$SELECTED_HOST_DOMAIN" ] \
                && ! domain_points_to_host "$SOVEREIGN_IDENTITY_DOMAIN" "$public_ip"; then
                warning "DNS for ${SOVEREIGN_IDENTITY_DOMAIN} does not appear to resolve to ${public_ip} yet."
            fi
        fi
    fi

    derive_sovereign_identity_from_host_domain
}

apply_host_domain_env() {
    if [ -z "$SELECTED_APP_URL" ]; then
        return 0
    fi

    set_env_var "APP_URL" "$SELECTED_APP_URL"
    set_env_var "SOVEREIGN_PANEL_URL" "$SELECTED_APP_URL"
    if [ -n "$SELECTED_HOST_DOMAIN" ]; then
        set_env_var "SOVEREIGN_HOST_DOMAIN" "$SELECTED_HOST_DOMAIN"
        set_env_var "SOVEREIGN_HOST_URL" "$SELECTED_APP_URL"
        local public_ip
        public_ip="$(detect_public_ip)"
        if [ -n "$public_ip" ]; then
            set_env_var "SOVEREIGN_HOST_PUBLIC_IP" "$public_ip"
        fi
        derive_sovereign_identity_from_host_domain
        apply_sovereign_identity_env
    fi
}

choose_simple_l1_cloudflare() {
    local zone_choice public_ip_choice current_token identity_domain require_token

    identity_domain="${SOVEREIGN_IDENTITY_DOMAIN:-${SELECTED_HOST_DOMAIN:-$SIMPLE_L1_DOMAIN}}"
    require_token="false"
    if looks_like_public_hostname "$identity_domain" && [ "${SOVEREIGN_REQUIRE_CLOUDFLARE_TOKEN:-true}" = "true" ]; then
        require_token="true"
    fi

    current_token="${SIMPLE_L1_CLOUDFLARE_API_TOKEN:-${CLOUDFLARE_API_TOKEN:-$(strip_env_quotes "$(get_env_var SIMPLE_L1_CLOUDFLARE_API_TOKEN 2>/dev/null || true)")}}"
    current_token="${current_token:-$(strip_env_quotes "$(get_env_var CLOUDFLARE_API_TOKEN 2>/dev/null || true)")}"
    if [ -n "$current_token" ]; then
        SIMPLE_L1_CLOUDFLARE_API_TOKEN="$current_token"
        SELECTED_SIMPLE_L1_CLOUDFLARE="true"
        if host_profile_is_mac_dev; then
            SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="off"
            SIMPLE_L1_DNS_STEERING_ENABLED="false"
        elif [ -z "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_CONFIGURED" ]; then
            SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="apply"
        fi
        export SIMPLE_L1_CLOUDFLARE_API_TOKEN
        export SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE SIMPLE_L1_DNS_STEERING_ENABLED
        note "Cloudflare token detected from environment or existing .env."
        return 0
    fi

    if ! can_prompt_cloudflare; then
        if [ "$require_token" = "true" ]; then
            die "Cloudflare token is required for ${identity_domain}. Set SIMPLE_L1_CLOUDFLARE_API_TOKEN or CLOUDFLARE_API_TOKEN."
        fi
        note "No Cloudflare token configured. Simple L1 will start, but DNS failover bootstrap will wait for SIMPLE_L1_CLOUDFLARE_API_TOKEN."
        return 0
    fi

    section "Cloudflare DNS for panel and Simple L1"
    if host_profile_is_mac_dev; then
        note "Mac dev uses Cloudflare Tunnel (CNAME), not A-record failover."
        note "Token scopes: Zone:Read, DNS:Edit, Cloudflare Tunnel:Edit."
    else
        note "Linux VPS uses Cloudflare DNS failover to this host public IP."
        note "Token scopes: Zone:Read, DNS:Edit."
    fi
    note "Domain: ${identity_domain}"

    while [ -z "${SIMPLE_L1_CLOUDFLARE_API_TOKEN:-}" ]; do
        printf 'Cloudflare API token: ' > /dev/tty
        read -rs SIMPLE_L1_CLOUDFLARE_API_TOKEN < /dev/tty
        printf '\n' > /dev/tty
        SIMPLE_L1_CLOUDFLARE_API_TOKEN="$(strip_env_quotes "$SIMPLE_L1_CLOUDFLARE_API_TOKEN")"
        if [ -z "$SIMPLE_L1_CLOUDFLARE_API_TOKEN" ]; then
            if [ "$require_token" = "true" ]; then
                warning "Cloudflare token is required for a public Sovereign host domain."
            else
                warning "Cloudflare token was empty. Skipping DNS failover bootstrap."
                return 0
            fi
        fi
    done

    if can_prompt; then
        printf 'Cloudflare zone ID (blank to auto-discover): ' > /dev/tty
        read -r zone_choice < /dev/tty
        SIMPLE_L1_CLOUDFLARE_ZONE_ID="$(strip_env_quotes "$zone_choice")"
    fi

    if [ -z "$SIMPLE_L1_PUBLIC_IP" ] && ! host_profile_is_mac_dev; then
        public_ip_choice="$(detect_public_ip)"
        if [ -n "$public_ip_choice" ]; then
            SIMPLE_L1_PUBLIC_IP="$public_ip_choice"
            note "Using detected public IP for this host: ${SIMPLE_L1_PUBLIC_IP}"
        fi
    fi

    if host_profile_is_mac_dev; then
        SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="off"
        SIMPLE_L1_DNS_STEERING_ENABLED="false"
    else
        SIMPLE_L1_DNS_STEERING_ENABLED="${SIMPLE_L1_DNS_STEERING_ENABLED:-true}"
        if [ "$SIMPLE_L1_DNS_STEERING_ENABLED" = "false" ]; then
            SIMPLE_L1_DNS_STEERING_ENABLED="true"
        fi
        if [ -z "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_CONFIGURED" ]; then
            SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="apply"
        fi
    fi
    SELECTED_SIMPLE_L1_CLOUDFLARE="true"
    export SIMPLE_L1_CLOUDFLARE_API_TOKEN SIMPLE_L1_CLOUDFLARE_ZONE_ID SIMPLE_L1_PUBLIC_IP SIMPLE_L1_DNS_STEERING_ENABLED SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE
}

apply_simple_l1_env() {
    set_env_var "SIMPLE_L1_IMAGE" "$SIMPLE_L1_IMAGE"
    set_env_var "SIMPLE_L1_DOMAIN" "$SIMPLE_L1_DOMAIN"
    set_env_var "SIMPLE_L1_ISSUER_URL" "$SIMPLE_L1_ISSUER_URL"
    set_env_var "SIMPLE_L1_NODE_NAME" "$SIMPLE_L1_NODE_NAME"
    set_env_var "SIMPLE_L1_NETWORK_NAME" "$SIMPLE_L1_NETWORK_NAME"
    set_env_var "SIMPLE_L1_NODE_TYPE_LABEL" "$SIMPLE_L1_NODE_TYPE_LABEL"
    set_env_var "SIMPLE_L1_SELF_WEBHOOK" "$SIMPLE_L1_SELF_WEBHOOK"
    set_env_var "SIMPLE_L1_PEERS" "$SIMPLE_L1_PEERS"
    set_env_var "SIMPLE_L1_STORAGE_ROLE" "$SIMPLE_L1_STORAGE_ROLE"
    set_env_var "SIMPLE_L1_IDENTITY_PROTOCOL_VERSION" "$SIMPLE_L1_IDENTITY_PROTOCOL_VERSION"
    set_env_var "SIMPLE_L1_IDENTITY_CAPSULES_ENABLED" "$SIMPLE_L1_IDENTITY_CAPSULES_ENABLED"
    set_env_var "SIMPLE_L1_EVIDENCE_RESOLVERS" "$SIMPLE_L1_EVIDENCE_RESOLVERS"
    set_env_var "SIMPLE_L1_STATE_RESOLVERS" "$SIMPLE_L1_STATE_RESOLVERS"
    set_env_var "SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL" "$SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL"
    set_env_var "SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE" "$SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE"
    set_env_var "SIMPLE_L1_DNS_TTL" "$SIMPLE_L1_DNS_TTL"
    set_env_var "SIMPLE_L1_DNS_STEERING_ENABLED" "$SIMPLE_L1_DNS_STEERING_ENABLED"
    set_env_var "SIMPLE_L1_CLOUDFLARE_PROXIED" "$SIMPLE_L1_CLOUDFLARE_PROXIED"
    set_env_var "SIMPLE_L1_CLOUDFLARE_API_TOKEN" "$SIMPLE_L1_CLOUDFLARE_API_TOKEN"
    set_env_var "SIMPLE_L1_CLOUDFLARE_ZONE_ID" "$SIMPLE_L1_CLOUDFLARE_ZONE_ID"
    set_env_var "SIMPLE_L1_PUBLIC_IP" "$SIMPLE_L1_PUBLIC_IP"
    set_env_var "SIMPLE_L1_FAILOVER_NODES" "$SIMPLE_L1_FAILOVER_NODES"
    set_env_var "SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND" "$SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND"
    set_env_var "SOVEREIGN_DNS_STEERING_SCHEDULE" "$SOVEREIGN_DNS_STEERING_SCHEDULE"
    set_env_var "SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE" "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE"
    set_env_var "SOVEREIGN_SIMPLE_L1_BOOTSTRAP" "$SOVEREIGN_SIMPLE_L1_BOOTSTRAP"
}

apply_digital_goods_source_env() {
    set_env_var "DIGITAL_GOODS_SOURCE_ENABLED" "$DIGITAL_GOODS_SOURCE_ENABLED"
    set_env_var "DIGITAL_GOODS_SOURCE_URL" "$DIGITAL_GOODS_SOURCE_URL"
    set_env_var "DIGITAL_GOODS_SOURCE_STATUS_URLS" "$DIGITAL_GOODS_SOURCE_STATUS_URLS"
    set_env_var "DIGITAL_GOODS_SOURCE_IMAGE" "$DIGITAL_GOODS_SOURCE_IMAGE"
    set_env_var "DIGITAL_GOODS_SOURCE_PORT" "$DIGITAL_GOODS_SOURCE_PORT"
    set_env_var "DIGITAL_GOODS_SOURCE_RUNTIME_VERSION" "$DIGITAL_GOODS_SOURCE_RUNTIME_VERSION"
    set_env_var "DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION" "$DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION"
    set_env_var "DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION" "$DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION"
    set_env_var "DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN" "$DIGITAL_GOODS_SOURCE_PLATFORM_TOKEN"
    set_env_var "DIGITAL_GOODS_SOURCE_FINANCIAL_SECRET" "$DIGITAL_GOODS_SOURCE_FINANCIAL_SECRET"
    set_env_var "DIGITAL_GOODS_SOURCE_SIGNATURE_TOLERANCE" "$DIGITAL_GOODS_SOURCE_SIGNATURE_TOLERANCE"
    set_env_var "DIGITAL_GOODS_SOURCE_DB_DATABASE" "$DIGITAL_GOODS_SOURCE_DB_DATABASE"
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

    if host_profile_is_mac_dev && [ "$SOVEREIGN_HARDENING" = "auto" ]; then
        SELECTED_HARDENING="false"
        note "Mac dev profile skips Linux host hardening (UFW/Fail2Ban)."
        return 0
    fi

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

sync_sovereign_runtime_scripts() {
    mkdir -p "${SOURCE_DIR}/scripts"
    download_file scripts/sovereign-identity-env.sh "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"
    download_file scripts/sovereign-host-profile.sh "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"
    download_file scripts/sovereign-mac-tunnel.sh "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh"
    download_file scripts/sovereign-mac-tunnel.sh "${SOURCE_DIR}/sovereign-mac-tunnel.sh"
    download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
    download_file scripts/sovereign-host-hardening.sh "${SOURCE_DIR}/sovereign-host-hardening.sh"
    download_file docker-compose.sovereign.mac.dev.yml "${SOURCE_DIR}/docker-compose.sovereign.mac.dev.yml"
    chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh" \
        "${SOURCE_DIR}/sovereign-host-hardening.sh" \
        "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh" \
        "${SOURCE_DIR}/sovereign-mac-tunnel.sh" 2>/dev/null || true
}

run_existing_upgrade() {
    local generate_claim="${1:-false}"

    log "Existing Coolify installation detected. Running sovereign runtime converge path (${SELECTED_INSTALL_MODE})."
    sync_sovereign_runtime_scripts

    SOVEREIGN_RUNTIME_CONVERGE_OWNER="$SOVEREIGN_RUNTIME_CONVERGE_OWNER" \
    SOVEREIGN_RUN_ID="$SOVEREIGN_RUN_ID" \
    SOVEREIGN_EXPECTED_RESULT="$SOVEREIGN_EXPECTED_RESULT" \
    SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE="$generate_claim" \
    SOVEREIGN_LOCAL_REPO_PATH="${SOVEREIGN_LOCAL_REPO_PATH:-}" \
    DOCKER_HOST="${DOCKER_HOST:-}" \
    DOCKER_DEFAULT_PLATFORM="${DOCKER_DEFAULT_PLATFORM:-}" \
    bash "${SOURCE_DIR}/upgrade-sovereign.sh"

    echo ""
    term_line "${C_GREEN}Sovereign runtime converge complete (${SELECTED_INSTALL_MODE}).${C_RESET}"
    if [ "$generate_claim" = "true" ]; then
        echo "Use the printed CLAIM_URL to bind the existing admin to SimpleL1 Identity."
    else
        echo "No admin claim was requested."
    fi
}

install_docker() {
    progress 1 4 "docker engine"
    if declare -F configure_mac_docker_env >/dev/null 2>&1; then
        configure_mac_docker_env
    fi

    if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
        if ! docker info >/dev/null 2>&1; then
            die "Docker is installed but the daemon is not reachable. On Mac: open Docker Desktop, then rerun with DOCKER_HOST=unix:///Users/${SUDO_USER:-$(id -un)}/.docker/run/docker.sock"
        fi
        log "Docker and Docker Compose are already installed"
        term_line "${C_GREEN}✓${C_RESET} docker engine ready"
        checkpoint 1 4 "DOCKER_READY" "Docker engine and Compose are available"
        return
    fi

    log "Installing Docker Engine"
    if [ "${SOVEREIGN_VERBOSE}" = "true" ]; then
        curl -fsSL https://get.docker.com | sh
    else
        run_logged "installing Docker Engine" bash -c 'curl -fsSL https://get.docker.com | sh'
    fi

    if command -v systemctl >/dev/null 2>&1; then
        run_logged "starting Docker service" systemctl enable --now docker
    fi
    checkpoint 1 4 "DOCKER_READY" "Docker engine and Compose are available"
}

login_to_registry() {
    if [ -n "${GHCR_USERNAME:-}" ] && [ -n "${GHCR_TOKEN:-}" ]; then
        log "Logging in to ghcr.io as ${GHCR_USERNAME}"
        if [ "${SOVEREIGN_VERBOSE}" = "true" ]; then
            echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USERNAME" --password-stdin
        else
            run_logged "logging in to ghcr.io" bash -c 'echo "$GHCR_TOKEN" | docker login ghcr.io -u "$GHCR_USERNAME" --password-stdin'
        fi
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

require_runtime_converge_owner

if [ "$EUID" -ne 0 ]; then
    die "Please run this script as root or with sudo."
fi

run_sovereign_disk_gate

mkdir -p "${SOURCE_DIR}" "${INSTALL_ROOT}"/{ssh,applications,databases,backups,services,proxy,sentinel}
mkdir -p "${INSTALL_ROOT}/ssh/keys" "${INSTALL_ROOT}/ssh/mux" "${INSTALL_ROOT}/proxy/dynamic"
touch "$LOG_FILE"

banner

progress_contract
load_sovereign_host_profile_helpers
load_sovereign_identity_env_helpers
check_host_resources
install_docker
login_to_registry

progress 2 4 "host detection"
DETECTED_INSTALL_STATE="$(detected_install_state)"
resolve_install_mode "$DETECTED_INSTALL_STATE"
choose_host_profile
choose_host_domain
choose_simple_l1_cloudflare
choose_hardening

section "Selected action"
note "Profile:    ${SELECTED_HOST_PROFILE}"
note "Mode:        ${SELECTED_INSTALL_MODE}"
note "Admin claim: ${SELECTED_ADMIN_CLAIM}"
note "Hardening:   ${SELECTED_HARDENING}"
note "SL1 DNS:     ${SELECTED_SIMPLE_L1_CLOUDFLARE}"
if [ -n "$SELECTED_APP_URL" ]; then
    note "APP_URL:     ${SELECTED_APP_URL}"
fi
if [ -n "$SL1_CONNECT_ISSUER" ] && ! sl1_identity_still_on_default_online; then
    note "SL1 issuer:  ${SL1_CONNECT_ISSUER}"
fi
echo ""
checkpoint 2 4 "HOST_DETECTED" "Install mode selected" "state=${DETECTED_INSTALL_STATE} mode=${SELECTED_INSTALL_MODE} app_url=${SELECTED_APP_URL:-unset}"

case "$SELECTED_INSTALL_MODE" in
    upgrade|refresh)
        progress 3 4 "runtime configuration"
        apply_host_domain_env
        apply_host_profile_env
        apply_simple_l1_env
        apply_digital_goods_source_env
        run_host_hardening
        checkpoint 3 4 "RUNTIME_CONFIGURED" "Runtime environment prepared" "mode=${SELECTED_INSTALL_MODE} hardening=${SELECTED_HARDENING}"
        progress 4 4 "runtime converge"
        run_existing_upgrade "$SELECTED_ADMIN_CLAIM"
        checkpoint 4 4 "RUNTIME_CONVERGED" "Sovereign runtime converge finished" "mode=${SELECTED_INSTALL_MODE}"
        exit 0
        ;;
    fresh)
        ;;
    *)
        die "Invalid selected install mode: ${SELECTED_INSTALL_MODE}"
        ;;
esac

log "Downloading Sovereign Coolify configuration"
progress 3 4 "runtime configuration"
download_file docker-compose.yml "${SOURCE_DIR}/docker-compose.yml"
download_file docker-compose.prod.yml "${SOURCE_DIR}/docker-compose.prod.yml"
download_file docker-compose.sovereign.prod.yml "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
download_file docker-compose.sovereign.mac.dev.yml "${SOURCE_DIR}/docker-compose.sovereign.mac.dev.yml"
download_file scripts/sovereign-identity-env.sh "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"
download_file scripts/sovereign-host-profile.sh "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"
download_file scripts/sovereign-mac-tunnel.sh "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh"
download_file .env.production "${SOURCE_DIR}/.env.production"
download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh"

merge_env_production
apply_host_domain_env
apply_host_profile_env
if host_profile_is_mac_dev; then
    ensure_mac_dev_compose_overlay || true
fi

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
set_env_var "SIMPLE_L1_IMAGE" "$SIMPLE_L1_IMAGE"
set_env_var "HELPER_IMAGE" "$(strip_image_tag "$HELPER_IMAGE")"
set_env_var "SOVEREIGN_REPOSITORY" "$REPOSITORY"
set_env_var "SOVEREIGN_BRANCH" "$BRANCH"
set_env_var "AUTOUPDATE" "$AUTOUPDATE"
set_env_var "SL1_CONNECT_ISSUER" "$SL1_CONNECT_ISSUER"
set_env_var "SL1_CONNECT_CLIENT_ID" "$SL1_CONNECT_CLIENT_ID"
set_env_var "SL1_CONNECT_CLIENT_NAME" "$SL1_CONNECT_CLIENT_NAME"
set_env_var "SL1_CONNECT_CALLBACK_PATH" "$SL1_CONNECT_CALLBACK_PATH"
set_env_var "SL1_CONNECT_TIMEOUT" "$SL1_CONNECT_TIMEOUT"
set_env_var "SIMPLE_L1_DOMAIN" "$SIMPLE_L1_DOMAIN"
set_env_var "SIMPLE_L1_ISSUER_URL" "$SIMPLE_L1_ISSUER_URL"
set_env_var "SIMPLE_L1_NODE_NAME" "$SIMPLE_L1_NODE_NAME"
set_env_var "SIMPLE_L1_NETWORK_NAME" "$SIMPLE_L1_NETWORK_NAME"
set_env_var "SIMPLE_L1_NODE_TYPE_LABEL" "$SIMPLE_L1_NODE_TYPE_LABEL"
set_env_var "SIMPLE_L1_SELF_WEBHOOK" "$SIMPLE_L1_SELF_WEBHOOK"
set_env_var "SIMPLE_L1_PEERS" "$SIMPLE_L1_PEERS"
set_env_var "SIMPLE_L1_STORAGE_ROLE" "$SIMPLE_L1_STORAGE_ROLE"
set_env_var "SIMPLE_L1_IDENTITY_PROTOCOL_VERSION" "$SIMPLE_L1_IDENTITY_PROTOCOL_VERSION"
set_env_var "SIMPLE_L1_IDENTITY_CAPSULES_ENABLED" "$SIMPLE_L1_IDENTITY_CAPSULES_ENABLED"
set_env_var "SIMPLE_L1_EVIDENCE_RESOLVERS" "$SIMPLE_L1_EVIDENCE_RESOLVERS"
set_env_var "SIMPLE_L1_STATE_RESOLVERS" "$SIMPLE_L1_STATE_RESOLVERS"
set_env_var "SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL" "$SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL"
set_env_var "SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE" "$SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE"
set_env_var "SIMPLE_L1_DNS_TTL" "$SIMPLE_L1_DNS_TTL"
set_env_var "SIMPLE_L1_DNS_STEERING_ENABLED" "$SIMPLE_L1_DNS_STEERING_ENABLED"
set_env_var "SIMPLE_L1_CLOUDFLARE_PROXIED" "$SIMPLE_L1_CLOUDFLARE_PROXIED"
set_env_var "SIMPLE_L1_CLOUDFLARE_API_TOKEN" "$SIMPLE_L1_CLOUDFLARE_API_TOKEN"
set_env_var "SIMPLE_L1_CLOUDFLARE_ZONE_ID" "$SIMPLE_L1_CLOUDFLARE_ZONE_ID"
set_env_var "SIMPLE_L1_PUBLIC_IP" "$SIMPLE_L1_PUBLIC_IP"
set_env_var "SIMPLE_L1_FAILOVER_NODES" "$SIMPLE_L1_FAILOVER_NODES"
apply_digital_goods_source_env
set_env_var "SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND" "$SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND"
set_env_var "SOVEREIGN_DNS_STEERING_SCHEDULE" "$SOVEREIGN_DNS_STEERING_SCHEDULE"
set_env_var "SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE" "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE"
set_env_var "SOVEREIGN_SIMPLE_L1_BOOTSTRAP" "$SOVEREIGN_SIMPLE_L1_BOOTSTRAP"
set_env_var "SOVEREIGN_HARDENING_PROFILE" "$SOVEREIGN_HARDENING_PROFILE"
set_env_var "SOVEREIGN_ALLOW_DIRECT_APP_PORT" "${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-false}"
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
checkpoint 3 4 "RUNTIME_CONFIGURED" "Runtime environment prepared" "mode=${SELECTED_INSTALL_MODE} app_url=${SELECTED_APP_URL:-unset}"

log "Starting Sovereign Coolify"
progress 4 4 "runtime converge"
SOVEREIGN_RUNTIME_CONVERGE_OWNER="$SOVEREIGN_RUNTIME_CONVERGE_OWNER" \
    SOVEREIGN_RUN_ID="$SOVEREIGN_RUN_ID" \
    SOVEREIGN_EXPECTED_RESULT="$SOVEREIGN_EXPECTED_RESULT" \
    bash "${SOURCE_DIR}/upgrade-sovereign.sh"
checkpoint 4 4 "RUNTIME_CONVERGED" "Sovereign runtime converge finished" "mode=${SELECTED_INSTALL_MODE}"

echo ""
echo "Sovereign Coolify installation complete."
if [ -n "$SELECTED_APP_URL" ]; then
    echo "Open: ${SELECTED_APP_URL}"
else
    echo "Open: http://$(hostname -I 2>/dev/null | awk '{print $1}'):${APP_PORT}"
fi
if [ -n "$SL1_CONNECT_ISSUER" ] && ! sl1_identity_still_on_default_online; then
    echo "SL1 Connect: ${SL1_CONNECT_ISSUER}"
fi
if host_profile_is_mac_dev; then
    write_mac_tunnel_config "$SELECTED_HOST_DOMAIN" "$APP_PORT" "${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}" "${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}" >/dev/null 2>&1 || true
    echo ""
    print_mac_tunnel_next_steps
fi
echo "Logs: ${LOG_FILE}"
