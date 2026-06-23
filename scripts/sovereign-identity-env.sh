#!/usr/bin/env bash

# Shared helpers: bind Sovereign panel domain to local Simple L1 / SL1e identity.

sovereign_identity_note() {
    if declare -F note >/dev/null 2>&1; then
        note "$@"
    else
        echo "   $*"
    fi
}

sovereign_identity_warning() {
    if declare -F warning >/dev/null 2>&1; then
        warning "$@"
    elif declare -F warn >/dev/null 2>&1; then
        warn "$@"
    else
        echo "WARNING: $*"
    fi
}

looks_like_public_hostname() {
    local host="$1"

    [ -n "$host" ] || return 1
    case "$host" in
        localhost|127.0.0.1|::1|0.0.0.0) return 1 ;;
    esac
    if [[ "$host" =~ ^[0-9.]+$ ]]; then
        return 1
    fi
    if [[ "$host" =~ : ]]; then
        return 1
    fi

    return 0
}

sl1_identity_still_on_default_online() {
    case "${SL1_CONNECT_ISSUER:-}" in
        ""|https://simplel1.online|http://simplel1.online) ;;
        *) return 1 ;;
    esac

    case "${SIMPLE_L1_DOMAIN:-}" in
        ""|simplel1.online) ;;
        *) return 1 ;;
    esac

    case "${SIMPLE_L1_ISSUER_URL:-}" in
        ""|https://simplel1.online/sl1|http://simplel1.online/sl1) ;;
        *) return 1 ;;
    esac

    return 0
}

derive_sovereign_identity_from_host_domain() {
    local host scheme issuer_url identity_host

    host="${1:-${SELECTED_HOST_DOMAIN:-${SOVEREIGN_HOST_DOMAIN:-}}}"
    host="$(printf '%s' "$host" | tr '[:upper:]' '[:lower:]')"
    [ -n "$host" ] || return 0
    looks_like_public_hostname "$host" || return 0
    sl1_identity_still_on_default_online || return 0

    identity_host="$(printf '%s' "${SOVEREIGN_IDENTITY_DOMAIN:-}" | tr '[:upper:]' '[:lower:]')"
    identity_host="${identity_host#"${identity_host%%[![:space:]]*}"}"
    identity_host="${identity_host%"${identity_host##*[![:space:]]}"}"
    if [ -z "$identity_host" ]; then
        identity_host="$host"
    fi

    scheme="${SOVEREIGN_APP_SCHEME:-https}"
    issuer_url="${scheme}://${identity_host}"

    SIMPLE_L1_DOMAIN="$identity_host"
    SL1_CONNECT_ISSUER="$issuer_url"
    SIMPLE_L1_ISSUER_URL="${issuer_url}/sl1"
    SOVEREIGN_RP_ID="$identity_host"

    if [ "$identity_host" != "$host" ]; then
        sovereign_identity_note "Panel host: ${host}  Simple L1 identity: ${issuer_url} (SL1e issuer ${SIMPLE_L1_ISSUER_URL})."
    else
        sovereign_identity_note "Simple L1 identity for this host: ${issuer_url} (SL1e issuer ${SIMPLE_L1_ISSUER_URL})."
    fi
}

apply_sovereign_identity_env() {
    set_env_var "SL1_CONNECT_ISSUER" "$SL1_CONNECT_ISSUER"
    set_env_var "SL1_CONNECT_CLIENT_ID" "$SL1_CONNECT_CLIENT_ID"
    set_env_var "SL1_CONNECT_CLIENT_NAME" "$SL1_CONNECT_CLIENT_NAME"
    set_env_var "SL1_CONNECT_CALLBACK_PATH" "$SL1_CONNECT_CALLBACK_PATH"
    set_env_var "SL1_CONNECT_TIMEOUT" "$SL1_CONNECT_TIMEOUT"
    set_env_var "SIMPLE_L1_DOMAIN" "$SIMPLE_L1_DOMAIN"
    set_env_var "SIMPLE_L1_ISSUER_URL" "$SIMPLE_L1_ISSUER_URL"
    if [ -n "${SOVEREIGN_IDENTITY_DOMAIN:-}" ]; then
        set_env_var "SOVEREIGN_IDENTITY_DOMAIN" "$SOVEREIGN_IDENTITY_DOMAIN"
    fi
    if [ -n "${SOVEREIGN_RP_ID:-}" ]; then
        set_env_var "SOVEREIGN_RP_ID" "$SOVEREIGN_RP_ID"
    fi
}

derive_sovereign_identity_env_from_runtime() {
    local host app_url

    host="$(strip_env_quotes "$(get_env_var SOVEREIGN_HOST_DOMAIN)")"
    if [ -z "$host" ]; then
        app_url="$(strip_env_quotes "$(get_env_var APP_URL)")"
        host="${app_url#*://}"
        host="${host%%/*}"
        host="${host%%:*}"
    fi

    SL1_CONNECT_ISSUER="${SL1_CONNECT_ISSUER:-$(strip_env_quotes "$(get_env_var SL1_CONNECT_ISSUER)")}"
    SIMPLE_L1_DOMAIN="${SIMPLE_L1_DOMAIN:-$(strip_env_quotes "$(get_env_var SIMPLE_L1_DOMAIN)")}"
    SIMPLE_L1_ISSUER_URL="${SIMPLE_L1_ISSUER_URL:-$(strip_env_quotes "$(get_env_var SIMPLE_L1_ISSUER_URL)")}"
    SOVEREIGN_APP_SCHEME="${SOVEREIGN_APP_SCHEME:-https}"

    derive_sovereign_identity_from_host_domain "$host"
}
