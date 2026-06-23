#!/usr/bin/env bash

set -euo pipefail

SOURCE_DIR="${COOLIFY_SOURCE_DIR:-/data/coolify/source}"
ENV_FILE="${SOURCE_DIR}/.env"
SOVEREIGN_TUNNEL_NAME="${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
SOVEREIGN_CLOUDFLARED_DIR="${SOVEREIGN_CLOUDFLARED_DIR:-${HOME}/.cloudflared}"
APP_PORT="${APP_PORT:-8000}"
SOVEREIGN_TUNNEL_SIMPLE_L1_PORT="${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}"

# shellcheck source=scripts/sovereign-host-profile.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/sovereign-host-profile.sh"

get_env_var() {
    local key="$1"
    grep -E "^${key}=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2- || true
}

strip_env_quotes() {
    local value="$1"
    value="${value%\"}"
    value="${value#\"}"
    value="${value%\'}"
    value="${value#\'}"
    printf '%s' "$value"
}

load_runtime_env() {
    if [ ! -f "$ENV_FILE" ]; then
        echo "Missing ${ENV_FILE}. Run Sovereign install first." >&2
        exit 1
    fi

    SOVEREIGN_HOST_DOMAIN="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_DOMAIN)")"
    APP_PORT="$(strip_env_quotes "$(get_env_var APP_PORT)")"
    APP_PORT="${APP_PORT:-8000}"
    SOVEREIGN_TUNNEL_NAME="$(strip_env_quotes "$(get_env_var SOVEREIGN_TUNNEL_NAME)")"
    SOVEREIGN_TUNNEL_NAME="${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
    SOVEREIGN_TUNNEL_SIMPLE_L1_PORT="$(strip_env_quotes "$(get_env_var SOVEREIGN_TUNNEL_SIMPLE_L1_PORT)")"
    SOVEREIGN_TUNNEL_SIMPLE_L1_PORT="${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}"

    if [ -z "$SOVEREIGN_HOST_DOMAIN" ]; then
        echo "SOVEREIGN_HOST_DOMAIN is not set in ${ENV_FILE}." >&2
        exit 1
    fi
}

require_cloudflared() {
    if ! command -v cloudflared >/dev/null 2>&1; then
        echo "cloudflared is not installed. Run: brew install cloudflared" >&2
        exit 1
    fi
}

cmd_write_config() {
    local config_path

    load_runtime_env
    config_path="$(write_mac_tunnel_config "$SOVEREIGN_HOST_DOMAIN" "$APP_PORT" "$SOVEREIGN_TUNNEL_SIMPLE_L1_PORT" "$SOVEREIGN_TUNNEL_NAME")"
    echo "Wrote ${config_path}"
}

cmd_setup() {
    local credentials

    load_runtime_env
    require_cloudflared

    if [ ! -f "${SOVEREIGN_CLOUDFLARED_DIR}/cert.pem" ]; then
        echo "Run cloudflared tunnel login first." >&2
        exit 1
    fi

    credentials="$(cloudflared_credentials_path "$SOVEREIGN_TUNNEL_NAME")"
    if [ -z "$credentials" ] || [ ! -f "$credentials" ]; then
        echo "Creating tunnel ${SOVEREIGN_TUNNEL_NAME}..."
        cloudflared tunnel create "$SOVEREIGN_TUNNEL_NAME"
        credentials="$(cloudflared_credentials_path "$SOVEREIGN_TUNNEL_NAME")"
    fi

    echo "Routing DNS ${SOVEREIGN_HOST_DOMAIN} -> tunnel ${SOVEREIGN_TUNNEL_NAME}..."
    cloudflared tunnel route dns "$SOVEREIGN_TUNNEL_NAME" "$SOVEREIGN_HOST_DOMAIN" || true

    cmd_write_config
    echo ""
    echo "Tunnel setup complete. Start with:"
    echo "  bash $(basename "$0") run"
}

cmd_run() {
    local config_path

    load_runtime_env
    require_cloudflared
    config_path="${SOURCE_DIR}/cloudflared/config.yml"
    if [ ! -f "$config_path" ]; then
        cmd_write_config
        config_path="${SOURCE_DIR}/cloudflared/config.yml"
    fi

    echo "Starting Cloudflare tunnel ${SOVEREIGN_TUNNEL_NAME} for https://${SOVEREIGN_HOST_DOMAIN}"
    exec cloudflared tunnel --config "$config_path" run "$SOVEREIGN_TUNNEL_NAME"
}

cmd_status() {
    load_runtime_env
    require_cloudflared

    echo "Domain:  ${SOVEREIGN_HOST_DOMAIN}"
    echo "Tunnel:  ${SOVEREIGN_TUNNEL_NAME}"
    echo "Panel:   http://127.0.0.1:${APP_PORT}"
    echo "Simple L1: http://127.0.0.1:${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT}"
    cloudflared tunnel list 2>/dev/null || true
}

usage() {
    cat <<EOF
Usage: $(basename "$0") <command>

Commands:
  write-config   Render ${SOURCE_DIR}/cloudflared/config.yml from .env
  setup          Create tunnel + DNS route + config (requires cloudflared login)
  run            Start the named tunnel
  status         Show tunnel/domain status
EOF
}

case "${1:-}" in
    write-config) cmd_write_config ;;
    setup) cmd_setup ;;
    run) cmd_run ;;
    status) cmd_status ;;
    *) usage; exit 1 ;;
esac
