#!/usr/bin/env bash

# Host profile helpers: mac-dev (Cloudflare Tunnel) vs linux-vps (public IP + Traefik).

configure_mac_docker_env() {
    if [ "$(uname -s)" != "Darwin" ]; then
        return 0
    fi

    local candidate sock user_home

    if [ -n "${DOCKER_HOST:-}" ] && docker info >/dev/null 2>&1; then
        return 0
    fi

    user_home="${HOME}"
    if [ "$(id -u)" -eq 0 ] && [ -n "${SUDO_USER:-}" ] && [ "${SUDO_USER}" != "root" ]; then
        user_home="$(eval echo "~${SUDO_USER}")"
    fi

    for candidate in \
        "${user_home}/.docker/run/docker.sock" \
        "/Users/${SUDO_USER:-$(id -un)}/.docker/run/docker.sock"; do
        if [ -S "$candidate" ]; then
            sock="$candidate"
            break
        fi
    done

    if [ -n "$sock" ]; then
        export DOCKER_HOST="unix://${sock}"
        sovereign_host_profile_note "Mac Docker socket: ${DOCKER_HOST}"
    fi

    export DOCKER_DEFAULT_PLATFORM="${DOCKER_DEFAULT_PLATFORM:-linux/amd64}"
    sovereign_host_profile_note "Mac Docker platform: ${DOCKER_DEFAULT_PLATFORM}"
}

detect_default_host_profile() {
    case "$(uname -s)" in
        Darwin) printf '%s' "mac-dev" ;;
        Linux) printf '%s' "linux-vps" ;;
        *) printf '%s' "linux-vps" ;;
    esac
}

normalize_host_profile() {
    case "$(printf '%s' "${1:-}" | tr '[:upper:]' '[:lower:]')" in
        mac|macos|mac-dev|darwin) printf '%s' "mac-dev" ;;
        linux|vps|linux-vps|server) printf '%s' "linux-vps" ;;
        "") printf '%s' "" ;;
        *) return 1 ;;
    esac
}

host_profile_is_mac_dev() {
    [ "${SELECTED_HOST_PROFILE:-${SOVEREIGN_HOST_PROFILE:-}}" = "mac-dev" ]
}

host_profile_is_linux_vps() {
    [ "${SELECTED_HOST_PROFILE:-${SOVEREIGN_HOST_PROFILE:-}}" = "linux-vps" ]
}

sovereign_host_profile_note() {
    if declare -F note >/dev/null 2>&1; then
        note "$@"
    else
        echo "   $*"
    fi
}

apply_host_profile_shell_defaults() {
    case "${SELECTED_HOST_PROFILE:-}" in
        mac-dev)
            SOVEREIGN_SIMPLE_L1_BOOTSTRAP="${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-skip}"
            SOVEREIGN_ALLOW_DIRECT_APP_PORT="${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-true}"
            SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"
            DOCKER_DEFAULT_PLATFORM="${DOCKER_DEFAULT_PLATFORM:-linux/amd64}"
            if [ -z "$SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE_CONFIGURED" ]; then
                SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE="off"
            fi
            SIMPLE_L1_DNS_STEERING_ENABLED="${SIMPLE_L1_DNS_STEERING_ENABLED:-false}"
            ;;
        linux-vps)
            SOVEREIGN_ALLOW_DIRECT_APP_PORT="${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-false}"
            SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"
            SOVEREIGN_SIMPLE_L1_BOOTSTRAP="${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-auto}"
            ;;
    esac
}

apply_host_profile_env() {
    set_env_var "SOVEREIGN_HOST_PROFILE" "${SELECTED_HOST_PROFILE:-linux-vps}"
    set_env_var "SOVEREIGN_ALLOW_DIRECT_APP_PORT" "${SOVEREIGN_ALLOW_DIRECT_APP_PORT:-false}"
    set_env_var "SOVEREIGN_SIMPLE_L1_BOOTSTRAP" "${SOVEREIGN_SIMPLE_L1_BOOTSTRAP:-auto}"
    if [ "${SELECTED_HOST_PROFILE:-}" = "mac-dev" ]; then
        set_env_var "DOCKER_DEFAULT_PLATFORM" "${DOCKER_DEFAULT_PLATFORM:-linux/amd64}"
        set_env_var "SOVEREIGN_TUNNEL_NAME" "${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
        set_env_var "SOVEREIGN_TUNNEL_SIMPLE_L1_PORT" "${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}"
    fi
}

ensure_mac_dev_compose_overlay() {
    local overlay="${SOURCE_DIR}/docker-compose.sovereign.mac.dev.yml"

    if [ -f "$overlay" ]; then
        return 0
    fi

    if declare -F download_file >/dev/null 2>&1; then
        download_file docker-compose.sovereign.mac.dev.yml "$overlay"
        return 0
    fi

    return 1
}

build_sovereign_compose_files() {
    local profile="${1:-$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_PROFILE)")}"
    local overlay="${SOURCE_DIR}/docker-compose.sovereign.mac.dev.yml"

    COMPOSE_FILES=(
        -f "${SOURCE_DIR}/docker-compose.yml"
        -f "${SOURCE_DIR}/docker-compose.prod.yml"
    )
    if [ -f "${SOURCE_DIR}/docker-compose.custom.yml" ]; then
        COMPOSE_FILES+=(-f "${SOURCE_DIR}/docker-compose.custom.yml")
    fi
    COMPOSE_FILES+=(-f "${SOURCE_DIR}/docker-compose.sovereign.prod.yml")
    if [ "$profile" = "mac-dev" ] && [ -f "$overlay" ]; then
        COMPOSE_FILES+=(-f "$overlay")
    fi
}

cloudflared_credentials_path() {
    local tunnel_name="${1:-${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}}"
    local cloudflared_dir="${SOVEREIGN_CLOUDFLARED_DIR:-${HOME}/.cloudflared}"

    if [ -f "${cloudflared_dir}/${tunnel_name}.json" ]; then
        printf '%s' "${cloudflared_dir}/${tunnel_name}.json"
        return 0
    fi

    find "${cloudflared_dir}" -maxdepth 1 -name '*.json' -type f 2>/dev/null | head -n 1
}

render_mac_tunnel_config() {
    local domain="$1"
    local app_port="$2"
    local sl1_port="$3"
    local tunnel_name="$4"
    local credentials_file="$5"

    cat <<EOF
# Generated by Sovereign Coolify (${tunnel_name})
tunnel: ${tunnel_name}
credentials-file: ${credentials_file}

ingress:
  - hostname: ${domain}
    path: /authorize*
    service: http://127.0.0.1:${sl1_port}
  - hostname: ${domain}
    path: /api/sl1e*
    service: http://127.0.0.1:${sl1_port}
  - hostname: ${domain}
    path: /healthcheck
    service: http://127.0.0.1:${sl1_port}
  - hostname: ${domain}
    path: /sl1*
    service: http://127.0.0.1:${app_port}
  - hostname: ${domain}
    service: http://127.0.0.1:${app_port}
  - service: http_status:404
EOF
}

write_mac_tunnel_config() {
    local domain app_port sl1_port tunnel_name credentials target_dir target_file

    domain="$(printf '%s' "${1:-${SELECTED_HOST_DOMAIN:-${SOVEREIGN_HOST_DOMAIN:-}}}" | tr '[:upper:]' '[:lower:]')"
    [ -n "$domain" ] || return 1

    app_port="${2:-${APP_PORT:-8000}}"
    sl1_port="${3:-${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}}"
    tunnel_name="${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
    credentials="$(cloudflared_credentials_path "$tunnel_name")"
    if [ -z "$credentials" ]; then
        credentials="${SOVEREIGN_CLOUDFLARED_DIR:-${HOME}/.cloudflared}/${tunnel_name}.json"
    fi

    target_dir="${SOURCE_DIR}/cloudflared"
    target_file="${target_dir}/config.yml"
    mkdir -p "$target_dir"
    render_mac_tunnel_config "$domain" "$app_port" "$sl1_port" "$tunnel_name" "$credentials" > "$target_file"

    if declare -F set_env_var >/dev/null 2>&1; then
        set_env_var "SOVEREIGN_CLOUDFLARED_CONFIG" "$target_file"
    fi

    printf '%s' "$target_file"
}

print_mac_tunnel_next_steps() {
    local domain="${SELECTED_HOST_DOMAIN:-${SOVEREIGN_HOST_DOMAIN:-}}"
    local tunnel_name="${SOVEREIGN_TUNNEL_NAME:-sovereign-mac}"
    local config_path="${SOURCE_DIR}/cloudflared/config.yml"
    local tunnel_script="${SOURCE_DIR}/sovereign-mac-tunnel.sh"

    sovereign_host_profile_note "Mac dev profile: public access is via Cloudflare named tunnel, not A-records."
    sovereign_host_profile_note "Panel local port: ${APP_PORT:-8000}  Simple L1 local port: ${SOVEREIGN_TUNNEL_SIMPLE_L1_PORT:-3000}"
    echo ""
    sovereign_host_profile_note "1) Install cloudflared: brew install cloudflared"
    sovereign_host_profile_note "2) Login and create tunnel:"
    sovereign_host_profile_note "   cloudflared tunnel login"
    sovereign_host_profile_note "   cloudflared tunnel create ${tunnel_name}"
    sovereign_host_profile_note "   cloudflared tunnel route dns ${tunnel_name} ${domain}"
    sovereign_host_profile_note "3) Render ingress config:"
    sovereign_host_profile_note "   bash ${tunnel_script} write-config"
    sovereign_host_profile_note "4) Run tunnel:"
    sovereign_host_profile_note "   bash ${tunnel_script} run"
    sovereign_host_profile_note "   # or: cloudflared tunnel --config ${config_path} run ${tunnel_name}"
    echo ""
    sovereign_host_profile_note "Open: https://${domain}"
}
