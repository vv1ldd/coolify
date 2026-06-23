#!/usr/bin/env bash

set -euo pipefail

DATE="$(date +"%Y%m%d-%H%M%S")"
SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="${SOURCE_DIR}/.env"
STATUS_FILE="${SOURCE_DIR}/.upgrade-sovereign-status"
LOG_FILE="${SOURCE_DIR}/upgrade-sovereign-${DATE}.log"
CONVERGE_STATE_FILE="${SOVEREIGN_CONVERGE_STATE_FILE:-/var/lib/sovereign/converge.state}"
REPOSITORY="${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}"
BRANCH="${SOVEREIGN_BRANCH:-sovereign}"
RAW_BASE="${SOVEREIGN_RAW_BASE:-https://raw.githubusercontent.com/${REPOSITORY}/${BRANCH}}"
SOVEREIGN_RUNTIME_CONVERGE_OWNER="${SOVEREIGN_RUNTIME_CONVERGE_OWNER:-false}"
SOVEREIGN_VERBOSE="${SOVEREIGN_VERBOSE:-false}"
SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE="${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE:-false}"
SOVEREIGN_RUN_ID="${SOVEREIGN_RUN_ID:-$DATE}"
SOVEREIGN_EXPECTED_RESULT="${SOVEREIGN_EXPECTED_RESULT:-sovereign-coolify-runtime-converged}"
CONVERGE_TOTAL="${SOVEREIGN_CONVERGE_TOTAL:-7}"
if [ "${SOVEREIGN_ADMIN_CLAIM_AFTER_UPGRADE}" = "true" ] && [ -z "${SOVEREIGN_CONVERGE_TOTAL:-}" ]; then
    CONVERGE_TOTAL=8
fi
_UPGRADE_SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || pwd)"
if [ -z "${SOVEREIGN_LOCAL_REPO_PATH:-}" ] && [ -f "${_UPGRADE_SCRIPT_DIR}/../docker-compose.yml" ]; then
    SOVEREIGN_LOCAL_REPO_PATH="$(cd "${_UPGRADE_SCRIPT_DIR}/.." && pwd)"
fi

if [ -z "${NO_COLOR:-}" ]; then
    C_RESET="$(printf '\033[0m')"
    C_DIM="$(printf '\033[2m')"
    C_CYAN="$(printf '\033[36m')"
    C_MAGENTA="$(printf '\033[35m')"
    C_GREEN="$(printf '\033[32m')"
else
    C_RESET=""
    C_DIM=""
    C_CYAN=""
    C_MAGENTA=""
    C_GREEN=""
fi

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

phase() {
    echo "${C_MAGENTA}▸${C_RESET} ${C_CYAN}$*${C_RESET}" | tee -a "$LOG_FILE"
}

note() {
    echo "${C_DIM}   $*${C_RESET}" | tee -a "$LOG_FILE"
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

    echo "${C_MAGENTA}[${bar}]${C_RESET} ${C_CYAN}${current}/${total}${C_RESET} ${C_GREEN}${percent}%${C_RESET} ${label}" | tee -a "$LOG_FILE"
}

show_log_tail() {
    echo "ERROR: Command failed. Last log lines from ${LOG_FILE}:"
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
        echo "${C_GREEN}✓${C_RESET} ${label}" | tee -a "$LOG_FILE"
        return 0
    fi

    {
        show_log_tail
        return "${rc}"
    }
}

write_status() {
    echo "$1|$2|$(date -Iseconds)" > "$STATUS_FILE"
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
    echo "${C_GREEN}✓${C_RESET} ${C_CYAN}${percent}%${C_RESET} ${code} ${C_DIM}fp:${fp}${C_RESET} ${message}" | tee -a "$LOG_FILE"
    if [ -n "$detail" ]; then
        echo "${C_DIM}   ${detail}${C_RESET}" | tee -a "$LOG_FILE"
    fi
}

final_fingerprint() {
    fingerprint "${SOVEREIGN_RUN_ID}|${SOVEREIGN_EXPECTED_RESULT}|${CONVERGE_TOTAL}/${CONVERGE_TOTAL}|CONVERGE_COMPLETE|Sovereign runtime converge complete|${REPOSITORY}|${BRANCH}"
}

progress_contract() {
    phase "runtime converge contract"
    note "Run:      ${SOVEREIGN_RUN_ID}"
    note "Target:   ${SOVEREIGN_EXPECTED_RESULT}"
    note "Final fp: $(final_fingerprint)"
    note "Status:   ${STATUS_FILE}"
    note "Format:   ✓ <percent> <checkpoint> fp:<fingerprint> <result>"
}

require_runtime_converge_owner() {
    if [ "${SOVEREIGN_RUNTIME_CONVERGE_OWNER}" != "true" ]; then
        echo "[runtime] converge owner = external, skipping mutations"
        exit 0
    fi

    echo "[runtime] converge owner = runtime, executing mutations"
}

mark_converge_state() {
    local state="$1"
    mkdir -p "$(dirname "${CONVERGE_STATE_FILE}")"
    printf '%s|%s|repository=%s|branch=%s\n' "${state}" "$(date -Iseconds)" "${REPOSITORY}" "${BRANCH}" >> "${CONVERGE_STATE_FILE}"
}

observe_panel_tls() {
    local app_url="$1"
    local timeout="${SOVEREIGN_PANEL_TLS_TIMEOUT:-180}"
    local interval="${SOVEREIGN_PANEL_TLS_INTERVAL:-5}"
    local elapsed=0
    local tls_state="pending_acme"

    case "${app_url}" in
        https://*) ;;
        *) return 0 ;;
    esac

    write_status "postflight" "Observing panel TLS certificate"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] Postflight observing panel TLS at ${app_url}" >> "$LOG_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] panel_tls_state=${tls_state} url=${app_url}" >> "$LOG_FILE"

    while [ "${elapsed}" -le "${timeout}" ]; do
        if curl -sS -I --max-time 10 "${app_url}" >/dev/null 2>>"$LOG_FILE"; then
            tls_state="trusted"
            echo "[$(date '+%Y-%m-%d %H:%M:%S')] panel_tls_state=${tls_state} url=${app_url}" >> "$LOG_FILE"
            return 0
        fi

        sleep "${interval}"
        elapsed=$((elapsed + interval))
    done

    if curl -k -sS -I --max-time 10 "${app_url}" >/dev/null 2>>"$LOG_FILE"; then
        tls_state="fallback_self_signed"
    else
        tls_state="unreachable"
    fi

    echo "[$(date '+%Y-%m-%d %H:%M:%S')] panel_tls_state=${tls_state} url=${app_url}" >> "$LOG_FILE"
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] TLS is postflight-only; converge remains complete. Check DNS A, ports 80/443, and Traefik ACME logs if this does not settle." >> "$LOG_FILE"
    return 0
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

digital_goods_source_enabled() {
    local enabled
    enabled="${DIGITAL_GOODS_SOURCE_ENABLED:-$(strip_env_quotes "$(get_env_var DIGITAL_GOODS_SOURCE_ENABLED)")}"
    enabled="${enabled:-false}"
    case "$enabled" in
        true|1|yes|on)
            return 0
            ;;
        *)
            return 1
            ;;
    esac
}

load_sovereign_identity_env_module() {
    local scripts_dir module_path

    scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    for module_path in \
        "${scripts_dir}/scripts/sovereign-identity-env.sh" \
        "${scripts_dir}/sovereign-identity-env.sh" \
        "${SOURCE_DIR}/scripts/sovereign-identity-env.sh" \
        "${SOVEREIGN_LOCAL_REPO_PATH:-}/scripts/sovereign-identity-env.sh"; do
        if [ -n "$module_path" ] && [ -f "$module_path" ]; then
            # shellcheck source=scripts/sovereign-identity-env.sh
            . "$module_path"
            return 0
        fi
    done

    echo "Could not find sovereign-identity-env.sh next to upgrade-sovereign.sh." >&2
    exit 1
}

load_sovereign_host_profile_module() {
    local scripts_dir module_path

    scripts_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    for module_path in \
        "${scripts_dir}/scripts/sovereign-host-profile.sh" \
        "${scripts_dir}/sovereign-host-profile.sh" \
        "${SOURCE_DIR}/scripts/sovereign-host-profile.sh" \
        "${SOVEREIGN_LOCAL_REPO_PATH:-}/scripts/sovereign-host-profile.sh"; do
        if [ -n "$module_path" ] && [ -f "$module_path" ]; then
            # shellcheck source=scripts/sovereign-host-profile.sh
            . "$module_path"
            return 0
        fi
    done

    echo "Could not find sovereign-host-profile.sh next to upgrade-sovereign.sh." >&2
    exit 1
}

load_sovereign_helper_modules() {
    load_sovereign_identity_env_module
    load_sovereign_host_profile_module
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

    if [ -n "${SOVEREIGN_LOCAL_REPO_PATH:-}" ] && [ -f "${SOVEREIGN_LOCAL_REPO_PATH}/${source_path}" ]; then
        log "Using local repo file ${source_path}"
        cp "${SOVEREIGN_LOCAL_REPO_PATH}/${source_path}" "$target_path"
        return 0
    fi

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

ensure_runtime_secrets() {
    set_env_var_if_empty "APP_ID" "$(openssl rand -hex 16)"
    set_env_var_if_empty "APP_KEY" "base64:$(openssl rand -base64 32)"
    set_env_var_if_empty "DB_PASSWORD" "$(openssl rand -base64 32)"
    set_env_var_if_empty "REDIS_PASSWORD" "$(openssl rand -base64 32)"
    set_env_var_if_empty "PUSHER_APP_ID" "$(openssl rand -hex 32)"
    set_env_var_if_empty "PUSHER_APP_KEY" "$(openssl rand -hex 32)"
    set_env_var_if_empty "PUSHER_APP_SECRET" "$(openssl rand -hex 32)"
    configure_database_env
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

    local claim_step
    claim_step=$((CONVERGE_TOTAL - 1))

    progress "$claim_step" "$CONVERGE_TOTAL" "admin claim"
    write_status "$claim_step" "Generating admin claim link"
    log "Generating one-time SimpleL1 admin claim link"

    local base_url
    base_url="$(public_base_url)"

    if run_logged "generating admin claim" docker exec coolify php artisan sovereign:admin-claim --auto --base-url="$base_url"; then
        checkpoint "$claim_step" "$CONVERGE_TOTAL" "ADMIN_CLAIM_READY" "One-time SL1 admin claim generated" "base_url=${base_url}"
    else
        log "Automatic admin claim link was not generated. Run manually after selecting an admin user:"
        log "docker exec -it coolify php artisan sovereign:admin-claim --user-id=<id> --base-url=${base_url}"
        checkpoint "$claim_step" "$CONVERGE_TOTAL" "ADMIN_CLAIM_MANUAL" "Admin claim needs manual generation" "base_url=${base_url}"
    fi
}

sync_host_domain() {
    local app_url host_domain
    app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"
    host_domain="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_DOMAIN)")"

    if [ -z "$app_url" ] || [ "$app_url" = "http://localhost" ] || [ "$app_url" = "https://localhost" ]; then
        checkpoint 6 "$CONVERGE_TOTAL" "DOMAIN_ROUTING_SKIPPED" "No public panel URL configured"
        return
    fi

    write_status "5" "Syncing host domain"
    log "Syncing host domain and panel URL"

    if ! run_logged "syncing host domain" docker exec coolify php artisan sovereign:sync-host-domain --url="$app_url" --domain="$host_domain"; then
        log "Host domain sync did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan sovereign:sync-host-domain --url=${app_url} --domain=${host_domain}"
        return 1
    fi

    run_logged "postflight panel TLS" observe_panel_tls "$app_url"
    checkpoint 6 "$CONVERGE_TOTAL" "DOMAIN_ROUTING_READY" "Panel domain synced and TLS postflight observed" "url=${app_url}"
}

sync_identity_policy() {
    write_status "4" "Syncing SL1 identity policy"
    log "Syncing SL1 identity policy"

    if ! run_logged "syncing identity policy" docker exec coolify php artisan sovereign:sync-identity-policy; then
        log "SL1 identity policy sync did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan sovereign:sync-identity-policy"
        return 1
    fi
    checkpoint 5 "$CONVERGE_TOTAL" "IDENTITY_POLICY_SYNCED" "SL1 identity policy is active"
}

bootstrap_simple_l1_failover() {
    local mode="${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-auto}"
    local profile
    profile="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_PROFILE)")"
    if [ "$profile" = "mac-dev" ]; then
        log "Simple L1 A-record bootstrap skipped for mac-dev profile (Cloudflare Tunnel handles DNS)."
        return 0
    fi
    case "$mode" in
        skip|false|off)
            log "Simple L1 failover bootstrap skipped."
            return 0
            ;;
    esac

    if run_logged "bootstrapping Simple L1 failover" docker exec coolify php artisan sovereign:simple-l1-bootstrap --json; then
        log "Simple L1 failover policy is ready."
    else
        log "Simple L1 failover bootstrap did not complete. Set SIMPLE_L1_CLOUDFLARE_API_TOKEN and SIMPLE_L1_PUBLIC_IP/SIMPLE_L1_FAILOVER_NODES, then run:"
        log "docker exec coolify php artisan sovereign:simple-l1-bootstrap --enable"
    fi
}

verify_simple_l1_identity_runtime() {
    local expected_protocol
    expected_protocol="${SIMPLE_L1_IDENTITY_PROTOCOL_VERSION:-$(strip_env_quotes "$(get_env_var SIMPLE_L1_IDENTITY_PROTOCOL_VERSION)")}"
    expected_protocol="${expected_protocol:-capsule-v0}"

    run_logged "verifying Simple L1 identity runtime" \
        docker exec -e EXPECTED_PROTOCOL_VERSION="$expected_protocol" simple-l1 node -e "fetch('http://127.0.0.1:3000/api/sl1e/connect/status').then(async (response) => { const payload = await response.json(); if (!response.ok) throw new Error('status endpoint returned ' + response.status); if (payload.protocol_version !== process.env.EXPECTED_PROTOCOL_VERSION) throw new Error('protocol_version mismatch: ' + payload.protocol_version + ' expected ' + process.env.EXPECTED_PROTOCOL_VERSION); if (payload.storage_role !== 'cache') throw new Error('storage_role is not cache: ' + payload.storage_role); if (payload.identity_capsules_enabled !== true) throw new Error('identity capsules are not enabled'); console.log(JSON.stringify({ ok: true, protocol_version: payload.protocol_version, storage_role: payload.storage_role, capsule_support: payload.identity_capsules_enabled })); }).catch((error) => { console.error(error.message); process.exit(1); });"
}

verify_digital_goods_source_runtime() {
    if ! digital_goods_source_enabled; then
        log "Digital Goods Source runtime skipped (DIGITAL_GOODS_SOURCE_ENABLED=false)."
        return 0
    fi

    local expected_kernel expected_provider
    expected_kernel="${DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION:-$(strip_env_quotes "$(get_env_var DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION)")}"
    expected_provider="${DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION:-$(strip_env_quotes "$(get_env_var DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION)")}"
    expected_kernel="${expected_kernel:-v1}"
    expected_provider="${expected_provider:-v1}"

    run_logged "verifying Digital Goods Source runtime" \
        docker exec -e EXPECTED_KERNEL_PROTOCOL_VERSION="$expected_kernel" -e EXPECTED_PROVIDER_CONTRACT_VERSION="$expected_provider" digital-goods-source php -r "\$payload = json_decode(file_get_contents('http://127.0.0.1:8080/api/v1/status'), true); if (!is_array(\$payload)) { fwrite(STDERR, 'invalid status payload'.PHP_EOL); exit(1); } if ((\$payload['kernel_protocol_version'] ?? null) !== getenv('EXPECTED_KERNEL_PROTOCOL_VERSION')) { fwrite(STDERR, 'kernel_protocol_version mismatch'.PHP_EOL); exit(1); } if ((\$payload['provider_contract_version'] ?? null) !== getenv('EXPECTED_PROVIDER_CONTRACT_VERSION')) { fwrite(STDERR, 'provider_contract_version mismatch'.PHP_EOL); exit(1); } echo json_encode(['ok' => true, 'kernel_protocol_version' => \$payload['kernel_protocol_version'], 'provider_contract_version' => \$payload['provider_contract_version']]).PHP_EOL;"
}

run_migrations() {
    write_status "4" "Running database migrations"
    log "Running Coolify migrations"

    if ! run_logged "running database migrations" docker exec coolify php artisan migrate --force; then
        log "Coolify migrations did not complete automatically. You can run manually:"
        log "docker exec coolify php artisan migrate --force"
        return 1
    fi
    checkpoint 4 "$CONVERGE_TOTAL" "MIGRATIONS_APPLIED" "Database schema is current"
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

require_runtime_converge_owner

if [ "$EUID" -ne 0 ]; then
    echo "Please run this script as root or with sudo."
    exit 1
fi

mkdir -p "$SOURCE_DIR" "${SOURCE_DIR}/scripts"
touch "$LOG_FILE"

progress_contract
phase "runtime converge started"
progress 1 "$CONVERGE_TOTAL" "bootstrap"
mark_converge_state "BOOTSTRAP_STARTED"
write_status "1" "Downloading compose files"

download_file docker-compose.yml "${SOURCE_DIR}/docker-compose.yml"
download_file docker-compose.prod.yml "${SOURCE_DIR}/docker-compose.prod.yml"
download_file docker-compose.sovereign.prod.yml "${SOURCE_DIR}/docker-compose.sovereign.prod.yml"
download_file docker-compose.sovereign.mac.dev.yml "${SOURCE_DIR}/docker-compose.sovereign.mac.dev.yml"
download_file scripts/sovereign-identity-env.sh "${SOURCE_DIR}/scripts/sovereign-identity-env.sh"
download_file scripts/sovereign-host-profile.sh "${SOURCE_DIR}/scripts/sovereign-host-profile.sh"
download_file scripts/sovereign-mac-tunnel.sh "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh"
download_file scripts/sovereign-mac-tunnel.sh "${SOURCE_DIR}/sovereign-mac-tunnel.sh"
download_file .env.production "${SOURCE_DIR}/.env.production"
download_file scripts/upgrade-sovereign.sh "${SOURCE_DIR}/upgrade-sovereign.sh"
download_file scripts/sovereign-host-hardening.sh "${SOURCE_DIR}/sovereign-host-hardening.sh"
chmod +x "${SOURCE_DIR}/upgrade-sovereign.sh"
chmod +x "${SOURCE_DIR}/sovereign-host-hardening.sh"
chmod +x "${SOURCE_DIR}/scripts/sovereign-mac-tunnel.sh" "${SOURCE_DIR}/sovereign-mac-tunnel.sh"

load_sovereign_helper_modules
configure_mac_docker_env

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
SIMPLE_L1_IMAGE="${SIMPLE_L1_IMAGE:-$(get_env_var SIMPLE_L1_IMAGE)}"
SIMPLE_L1_IMAGE="${SIMPLE_L1_IMAGE:-ghcr.io/vv1ldd/simple-l1:latest}"
HELPER_IMAGE="${HELPER_IMAGE:-$(get_env_var HELPER_IMAGE)}"
HELPER_IMAGE="${HELPER_IMAGE:-ghcr.io/coollabsio/coolify-helper}"
SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-$(strip_env_quotes "$(get_env_var SOVEREIGN_APP_SCHEME)")}"
SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"
derive_sovereign_identity_env_from_runtime
SIMPLE_L1_DOMAIN_VALUE="${SIMPLE_L1_DOMAIN:-$(get_env_var SIMPLE_L1_DOMAIN)}"
SIMPLE_L1_DOMAIN_VALUE="${SIMPLE_L1_DOMAIN_VALUE:-simplel1.online}"
SIMPLE_L1_ISSUER_URL_VALUE="${SIMPLE_L1_ISSUER_URL:-$(get_env_var SIMPLE_L1_ISSUER_URL)}"
SIMPLE_L1_ISSUER_URL_VALUE="${SIMPLE_L1_ISSUER_URL_VALUE:-https://simplel1.online/sl1}"
SIMPLE_L1_NODE_NAME_VALUE="${SIMPLE_L1_NODE_NAME:-$(get_env_var SIMPLE_L1_NODE_NAME)}"
SIMPLE_L1_NODE_NAME_VALUE="${SIMPLE_L1_NODE_NAME_VALUE:-sovereign-coolify-node}"
SIMPLE_L1_NETWORK_NAME_VALUE="${SIMPLE_L1_NETWORK_NAME:-$(get_env_var SIMPLE_L1_NETWORK_NAME)}"
SIMPLE_L1_NETWORK_NAME_VALUE="${SIMPLE_L1_NETWORK_NAME_VALUE:-Simple-L1}"
SIMPLE_L1_NODE_TYPE_LABEL_VALUE="${SIMPLE_L1_NODE_TYPE_LABEL:-$(get_env_var SIMPLE_L1_NODE_TYPE_LABEL)}"
SIMPLE_L1_NODE_TYPE_LABEL_VALUE="${SIMPLE_L1_NODE_TYPE_LABEL_VALUE:-Sovereign Coolify Node}"
SIMPLE_L1_STORAGE_ROLE_VALUE="${SIMPLE_L1_STORAGE_ROLE:-$(get_env_var SIMPLE_L1_STORAGE_ROLE)}"
SIMPLE_L1_STORAGE_ROLE_VALUE="${SIMPLE_L1_STORAGE_ROLE_VALUE:-cache}"
SIMPLE_L1_IDENTITY_PROTOCOL_VERSION_VALUE="${SIMPLE_L1_IDENTITY_PROTOCOL_VERSION:-$(get_env_var SIMPLE_L1_IDENTITY_PROTOCOL_VERSION)}"
SIMPLE_L1_IDENTITY_PROTOCOL_VERSION_VALUE="${SIMPLE_L1_IDENTITY_PROTOCOL_VERSION_VALUE:-capsule-v0}"
DIGITAL_GOODS_SOURCE_IMAGE_VALUE="${DIGITAL_GOODS_SOURCE_IMAGE:-$(get_env_var DIGITAL_GOODS_SOURCE_IMAGE)}"
DIGITAL_GOODS_SOURCE_IMAGE_VALUE="${DIGITAL_GOODS_SOURCE_IMAGE_VALUE:-ghcr.io/vv1ldd/digital-goods-source:latest}"
DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION_VALUE="${DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION:-$(get_env_var DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION)}"
DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION_VALUE="${DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION_VALUE:-v1}"
DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION_VALUE="${DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION:-$(get_env_var DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION)}"
DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION_VALUE="${DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION_VALUE:-v1}"
SIMPLE_L1_IDENTITY_CAPSULES_ENABLED_VALUE="${SIMPLE_L1_IDENTITY_CAPSULES_ENABLED:-$(get_env_var SIMPLE_L1_IDENTITY_CAPSULES_ENABLED)}"
SIMPLE_L1_IDENTITY_CAPSULES_ENABLED_VALUE="${SIMPLE_L1_IDENTITY_CAPSULES_ENABLED_VALUE:-true}"
SIMPLE_L1_EVIDENCE_RESOLVERS_VALUE="${SIMPLE_L1_EVIDENCE_RESOLVERS:-$(get_env_var SIMPLE_L1_EVIDENCE_RESOLVERS)}"
SIMPLE_L1_EVIDENCE_RESOLVERS_VALUE="${SIMPLE_L1_EVIDENCE_RESOLVERS_VALUE:-local-cache,client-capsule,peer,signed-export}"
SIMPLE_L1_STATE_RESOLVERS_VALUE="${SIMPLE_L1_STATE_RESOLVERS:-$(get_env_var SIMPLE_L1_STATE_RESOLVERS)}"
SIMPLE_L1_STATE_RESOLVERS_VALUE="${SIMPLE_L1_STATE_RESOLVERS_VALUE:-local-cache,peer,anchor,quorum,signed-export}"
SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL_VALUE="${SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL:-$(get_env_var SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL)}"
SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL_VALUE="${SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL_VALUE:-AL1}"
SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE_VALUE="${SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE:-$(get_env_var SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE)}"
SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE_VALUE="${SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE_VALUE:-false}"
SIMPLE_L1_DNS_TTL_VALUE="${SIMPLE_L1_DNS_TTL:-$(get_env_var SIMPLE_L1_DNS_TTL)}"
SIMPLE_L1_DNS_TTL_VALUE="${SIMPLE_L1_DNS_TTL_VALUE:-60}"
SIMPLE_L1_DNS_STEERING_ENABLED_VALUE="${SIMPLE_L1_DNS_STEERING_ENABLED:-$(get_env_var SIMPLE_L1_DNS_STEERING_ENABLED)}"
SIMPLE_L1_DNS_STEERING_ENABLED_VALUE="${SIMPLE_L1_DNS_STEERING_ENABLED_VALUE:-false}"
SIMPLE_L1_CLOUDFLARE_PROXIED_VALUE="${SIMPLE_L1_CLOUDFLARE_PROXIED:-$(get_env_var SIMPLE_L1_CLOUDFLARE_PROXIED)}"
SIMPLE_L1_CLOUDFLARE_PROXIED_VALUE="${SIMPLE_L1_CLOUDFLARE_PROXIED_VALUE:-false}"
SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_VALUE="${SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE:-$(get_env_var SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE)}"
SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_VALUE="${SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_VALUE:-off}"

set_env_var "COOLIFY_IMAGE" "$COOLIFY_IMAGE"
set_env_var "SOVEREIGN_REALTIME_IMAGE" "$SOVEREIGN_REALTIME_IMAGE"
set_env_var "SIMPLE_L1_IMAGE" "$SIMPLE_L1_IMAGE"
set_env_var "HELPER_IMAGE" "$(strip_image_tag "$HELPER_IMAGE")"
set_env_var "SOVEREIGN_REPOSITORY" "$REPOSITORY"
set_env_var "SOVEREIGN_BRANCH" "$BRANCH"
set_env_var "AUTOUPDATE" "${AUTOUPDATE:-false}"
ensure_runtime_secrets
SL1_CONNECT_CLIENT_NAME_VALUE="${SL1_CONNECT_CLIENT_NAME:-$(get_env_var SL1_CONNECT_CLIENT_NAME)}"
SL1_CONNECT_CLIENT_NAME_VALUE="${SL1_CONNECT_CLIENT_NAME_VALUE:-Sovereign-Coolify}"
set_env_var "SL1_CONNECT_ISSUER" "${SL1_CONNECT_ISSUER:-$(get_env_var SL1_CONNECT_ISSUER)}"
set_env_var "SL1_CONNECT_CLIENT_ID" "${SL1_CONNECT_CLIENT_ID:-$(get_env_var SL1_CONNECT_CLIENT_ID)}"
set_env_var "SL1_CONNECT_CLIENT_NAME" "$SL1_CONNECT_CLIENT_NAME_VALUE"
set_env_var "SL1_CONNECT_CALLBACK_PATH" "${SL1_CONNECT_CALLBACK_PATH:-$(get_env_var SL1_CONNECT_CALLBACK_PATH)}"
set_env_var "SL1_CONNECT_TIMEOUT" "${SL1_CONNECT_TIMEOUT:-$(get_env_var SL1_CONNECT_TIMEOUT)}"
set_env_var "SIMPLE_L1_DOMAIN" "$SIMPLE_L1_DOMAIN_VALUE"
set_env_var "SIMPLE_L1_ISSUER_URL" "$SIMPLE_L1_ISSUER_URL_VALUE"
if [ -n "${SOVEREIGN_RP_ID:-}" ]; then
    set_env_var "SOVEREIGN_RP_ID" "$SOVEREIGN_RP_ID"
fi
set_env_var "SIMPLE_L1_NODE_NAME" "$SIMPLE_L1_NODE_NAME_VALUE"
set_env_var "SIMPLE_L1_NETWORK_NAME" "$SIMPLE_L1_NETWORK_NAME_VALUE"
set_env_var "SIMPLE_L1_NODE_TYPE_LABEL" "$SIMPLE_L1_NODE_TYPE_LABEL_VALUE"
set_env_var "SIMPLE_L1_SELF_WEBHOOK" "${SIMPLE_L1_SELF_WEBHOOK:-$(get_env_var SIMPLE_L1_SELF_WEBHOOK)}"
set_env_var "SIMPLE_L1_PEERS" "${SIMPLE_L1_PEERS:-$(get_env_var SIMPLE_L1_PEERS)}"
set_env_var "SIMPLE_L1_STORAGE_ROLE" "$SIMPLE_L1_STORAGE_ROLE_VALUE"
set_env_var "SIMPLE_L1_IDENTITY_PROTOCOL_VERSION" "$SIMPLE_L1_IDENTITY_PROTOCOL_VERSION_VALUE"
DIGITAL_GOODS_SOURCE_ENABLED_VALUE="${DIGITAL_GOODS_SOURCE_ENABLED:-$(strip_env_quotes "$(get_env_var DIGITAL_GOODS_SOURCE_ENABLED)")}"
DIGITAL_GOODS_SOURCE_ENABLED_VALUE="${DIGITAL_GOODS_SOURCE_ENABLED_VALUE:-false}"
set_env_var "DIGITAL_GOODS_SOURCE_ENABLED" "$DIGITAL_GOODS_SOURCE_ENABLED_VALUE"
if [ "$DIGITAL_GOODS_SOURCE_ENABLED_VALUE" = "true" ]; then
    set_env_var "DIGITAL_GOODS_SOURCE_IMAGE" "$DIGITAL_GOODS_SOURCE_IMAGE_VALUE"
    set_env_var "DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION" "$DIGITAL_GOODS_SOURCE_KERNEL_PROTOCOL_VERSION_VALUE"
    set_env_var "DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION" "$DIGITAL_GOODS_SOURCE_PROVIDER_CONTRACT_VERSION_VALUE"
fi
set_env_var "SIMPLE_L1_IDENTITY_CAPSULES_ENABLED" "$SIMPLE_L1_IDENTITY_CAPSULES_ENABLED_VALUE"
set_env_var "SIMPLE_L1_EVIDENCE_RESOLVERS" "$SIMPLE_L1_EVIDENCE_RESOLVERS_VALUE"
set_env_var "SIMPLE_L1_STATE_RESOLVERS" "$SIMPLE_L1_STATE_RESOLVERS_VALUE"
set_env_var "SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL" "$SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL_VALUE"
set_env_var "SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE" "$SIMPLE_L1_NAMESPACE_AUTO_ALLOCATE_VALUE"
set_env_var "SIMPLE_L1_DNS_TTL" "$SIMPLE_L1_DNS_TTL_VALUE"
set_env_var "SIMPLE_L1_DNS_STEERING_ENABLED" "$SIMPLE_L1_DNS_STEERING_ENABLED_VALUE"
set_env_var "SIMPLE_L1_CLOUDFLARE_PROXIED" "$SIMPLE_L1_CLOUDFLARE_PROXIED_VALUE"
set_env_var "SIMPLE_L1_CLOUDFLARE_API_TOKEN" "${SIMPLE_L1_CLOUDFLARE_API_TOKEN:-$(get_env_var SIMPLE_L1_CLOUDFLARE_API_TOKEN)}"
set_env_var "SIMPLE_L1_CLOUDFLARE_ZONE_ID" "${SIMPLE_L1_CLOUDFLARE_ZONE_ID:-$(get_env_var SIMPLE_L1_CLOUDFLARE_ZONE_ID)}"
set_env_var "SIMPLE_L1_PUBLIC_IP" "${SIMPLE_L1_PUBLIC_IP:-$(get_env_var SIMPLE_L1_PUBLIC_IP)}"
set_env_var "SIMPLE_L1_FAILOVER_NODES" "${SIMPLE_L1_FAILOVER_NODES:-$(get_env_var SIMPLE_L1_FAILOVER_NODES)}"
set_env_var "SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND" "${SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND:-$(get_env_var SOVEREIGN_CONTROL_PLANE_HEARTBEAT_SEND)}"
set_env_var "SOVEREIGN_DNS_STEERING_SCHEDULE" "${SOVEREIGN_DNS_STEERING_SCHEDULE:-$(get_env_var SOVEREIGN_DNS_STEERING_SCHEDULE)}"
set_env_var "SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE" "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_VALUE"
set_env_var "SOVEREIGN_SIMPLE_L1_BOOTSTRAP" "${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-$(get_env_var SOVEREIGN_SIMPLE_L1_BOOTSTRAP)}"
HOST_PROFILE_VALUE="${SOVEREIGN_HOST_PROFILE:-$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_PROFILE)")}"
if [ -z "$HOST_PROFILE_VALUE" ]; then
    HOST_PROFILE_VALUE="$(detect_default_host_profile)"
fi
set_env_var "SOVEREIGN_HOST_PROFILE" "$HOST_PROFILE_VALUE"
if [ "$HOST_PROFILE_VALUE" = "mac-dev" ]; then
    set_env_var "SOVEREIGN_ALLOW_DIRECT_APP_PORT" "${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-$(strip_env_quotes "$(get_env_var SOVEREIGN_ALLOW_DIRECT_APP_PORT)")}"
    if [ -z "$(strip_env_quotes "$(get_env_var SOVEREIGN_ALLOW_DIRECT_APP_PORT)")" ]; then
        set_env_var "SOVEREIGN_ALLOW_DIRECT_APP_PORT" "true"
    fi
    set_env_var "DOCKER_DEFAULT_PLATFORM" "${DOCKER_DEFAULT_PLATFORM:-$(strip_env_quotes "$(get_env_var DOCKER_DEFAULT_PLATFORM)")}"
    if [ -z "$(strip_env_quotes "$(get_env_var DOCKER_DEFAULT_PLATFORM)")" ]; then
        set_env_var "DOCKER_DEFAULT_PLATFORM" "linux/amd64"
    fi
    set_env_var "SOVEREIGN_TUNNEL_NAME" "${SOVEREIGN_TUNNEL_NAME:-$(strip_env_quotes "$(get_env_var SOVEREIGN_TUNNEL_NAME)")}"
    if [ -z "$(strip_env_quotes "$(get_env_var SOVEREIGN_TUNNEL_NAME)")" ]; then
        set_env_var "SOVEREIGN_TUNNEL_NAME" "sovereign-mac"
    fi
fi
checkpoint 1 "$CONVERGE_TOTAL" "BOOTSTRAP_READY" "Runtime files and environment are prepared" "repository=${REPOSITORY} branch=${BRANCH} profile=${HOST_PROFILE_VALUE}"

run_host_hardening

if ! docker network inspect coolify >/dev/null 2>&1; then
    log "Creating coolify network"
    docker network create --attachable coolify >/dev/null
fi

COMPOSE_FILES=()
build_sovereign_compose_files "$HOST_PROFILE_VALUE"
COMPOSE_DOCKER_ENV=()
if [ "$HOST_PROFILE_VALUE" = "mac-dev" ]; then
    COMPOSE_DOCKER_ENV+=(DOCKER_DEFAULT_PLATFORM="${DOCKER_DEFAULT_PLATFORM:-linux/amd64}")
fi

write_status "2" "Pulling images"
progress 2 "$CONVERGE_TOTAL" "pull images"
phase "pulling runtime images"
run_logged "pulling runtime images" env COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" SIMPLE_L1_IMAGE="$SIMPLE_L1_IMAGE" "${COMPOSE_DOCKER_ENV[@]}" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" pull
checkpoint 2 "$CONVERGE_TOTAL" "IMAGES_PULLED" "Runtime images are present locally" "coolify_image=${COOLIFY_IMAGE}"

write_status "3" "Starting containers"
progress 3 "$CONVERGE_TOTAL" "start containers"
phase "starting containers"
run_logged "starting containers" env COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" SIMPLE_L1_IMAGE="$SIMPLE_L1_IMAGE" "${COMPOSE_DOCKER_ENV[@]}" \
    docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" up -d --remove-orphans --wait --wait-timeout 120

if ! docker exec coolify getent hosts host.docker.internal >/dev/null 2>&1; then
    log "Recreating Coolify container so host.docker.internal resolves through host-gateway"
    run_logged "recreating Coolify host gateway" env COOLIFY_IMAGE="$COOLIFY_IMAGE" SOVEREIGN_REALTIME_IMAGE="$SOVEREIGN_REALTIME_IMAGE" SIMPLE_L1_IMAGE="$SIMPLE_L1_IMAGE" "${COMPOSE_DOCKER_ENV[@]}" \
        docker compose --env-file "$ENV_FILE" "${COMPOSE_FILES[@]}" up -d --force-recreate --no-deps --wait --wait-timeout 120 coolify
fi
mark_converge_state "CONTAINERS_STARTED"
checkpoint 3 "$CONVERGE_TOTAL" "CONTAINERS_STARTED" "Runtime containers are healthy" "host_gateway=ready"

progress 4 "$CONVERGE_TOTAL" "migrations"
run_migrations
mark_converge_state "MIGRATIONS_DONE"
progress 5 "$CONVERGE_TOTAL" "identity policy"
sync_identity_policy
bootstrap_simple_l1_failover
verify_simple_l1_identity_runtime
verify_digital_goods_source_runtime
mark_converge_state "POLICY_SYNCED"
progress 6 "$CONVERGE_TOTAL" "domain routing"
sync_host_domain
mark_converge_state "DOMAIN_SYNCED"
generate_admin_claim

write_status "done" "Sovereign runtime converge complete"
mark_converge_state "CONVERGE_COMPLETE"
progress "$CONVERGE_TOTAL" "$CONVERGE_TOTAL" "converge complete"
checkpoint "$CONVERGE_TOTAL" "$CONVERGE_TOTAL" "CONVERGE_COMPLETE" "Sovereign runtime converge complete"
log "Sovereign runtime converge complete"
