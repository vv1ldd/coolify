#!/usr/bin/env bash

set -euo pipefail

HARDENING_VERSION="2026-05-28.1"
PROFILE="${SOVEREIGN_HARDENING_PROFILE:-baseline}"
DRY_RUN="${SOVEREIGN_HARDENING_DRY_RUN:-false}"
MARKER_FILE="${SOVEREIGN_HARDENING_MARKER:-/etc/sovereign/host-hardening.json}"
APP_PORT="${APP_PORT:-8000}"
SOKETI_PORT="${SOKETI_PORT:-6001}"
EXPOSED_PORTS="${SOVEREIGN_EXPOSED_PORTS:-}"
EXPOSED_CIDRS="${SOVEREIGN_EXPOSED_CIDRS:-}"
WIREGUARD_CIDRS="${SOVEREIGN_WIREGUARD_CIDRS:-}"
ALLOW_PUBLIC_DATABASE_PORTS="${SOVEREIGN_ALLOW_PUBLIC_DATABASE_PORTS:-false}"
SMTP_MODE="${SOVEREIGN_SMTP_MODE:-none}"
SMTP_RELAY_HOST="${SOVEREIGN_SMTP_RELAY_HOST:-}"
SMTP_RELAY_PORT="${SOVEREIGN_SMTP_RELAY_PORT:-587}"
SMTP_RELAY_USERNAME="${SOVEREIGN_SMTP_RELAY_USERNAME:-}"
SMTP_RELAY_PASSWORD="${SOVEREIGN_SMTP_RELAY_PASSWORD:-}"
SMTP_FROM_DOMAIN="${SOVEREIGN_SMTP_FROM_DOMAIN:-${SOVEREIGN_HOST_DOMAIN:-}}"
SMTP_TEST_RECIPIENT="${SOVEREIGN_SMTP_TEST_RECIPIENT:-}"
HOST_DOMAIN="${SOVEREIGN_HOST_DOMAIN:-}"
HOST_PUBLIC_IP="${SOVEREIGN_HOST_PUBLIC_IP:-}"
PUBLIC_INTERFACE="${SOVEREIGN_PUBLIC_INTERFACE:-}"

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

line() {
    printf '%b\n' "$*"
}

info() {
    line "${C_CYAN}>> $*${C_RESET}"
}

note() {
    line "${C_DIM}   $*${C_RESET}"
}

warn() {
    line "${C_YELLOW}!! $*${C_RESET}"
}

die() {
    line "${C_RED}ERROR: $*${C_RESET}"
    exit 1
}

run() {
    if [ "$DRY_RUN" = "true" ]; then
        line "${C_DIM}+ $*${C_RESET}"
        return 0
    fi

    "$@"
}

run_shell() {
    if [ "$DRY_RUN" = "true" ]; then
        line "${C_DIM}+ $*${C_RESET}"
        return 0
    fi

    bash -c "$*"
}

csv_items() {
    printf '%s' "$1" | tr ',' ' '
}

detect_ssh_port() {
    if [ -n "${SOVEREIGN_SSH_PORT:-}" ]; then
        printf '%s' "$SOVEREIGN_SSH_PORT"
        return
    fi

    if [ -n "${SSH_PORT:-}" ]; then
        printf '%s' "$SSH_PORT"
        return
    fi

    if [ -n "${SSH_CONNECTION:-}" ]; then
        set -- $SSH_CONNECTION
        if [ -n "${4:-}" ]; then
            printf '%s' "$4"
            return
        fi
    fi

    if [ -r /etc/ssh/sshd_config ]; then
        local configured_port
        configured_port="$(awk 'tolower($1) == "port" && $0 !~ /^[[:space:]]*#/ { print $2; exit }' /etc/ssh/sshd_config)"
        if [ -n "$configured_port" ]; then
            printf '%s' "$configured_port"
            return
        fi
    fi

    printf '22'
}

detect_public_ip() {
    if [ -n "$HOST_PUBLIC_IP" ]; then
        printf '%s' "$HOST_PUBLIC_IP"
        return
    fi

    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 3 https://api.ipify.org 2>/dev/null || true
        return
    fi
}

detect_docker_cidrs() {
    if ! command -v docker >/dev/null 2>&1; then
        return
    fi

    docker network inspect coolify --format '{{range .IPAM.Config}}{{.Subnet}} {{end}}' 2>/dev/null || true
}

detect_published_ports() {
    if ! command -v docker >/dev/null 2>&1; then
        return
    fi

    docker ps --format '{{.Names}} {{.Ports}}' 2>/dev/null | sed '/^[[:space:]]*$/d' || true
}

is_database_port() {
    local port="${1%%/*}"

    case "$port" in
        3306|33060|5432|5433|6379|6380|27017|27018|9200|9300|11211|1433|1521)
            return 0
            ;;
    esac

    return 1
}

is_port_spec() {
    case "$1" in
        *"/tcp"|*"/udp")
            return 0
            ;;
    esac

    return 1
}

port_number() {
    printf '%s' "${1%%/*}"
}

port_proto() {
    if printf '%s' "$1" | grep -q '/udp$'; then
        printf 'udp'
        return
    fi

    printf 'tcp'
}

install_packages() {
    if ! command -v apt-get >/dev/null 2>&1; then
        if [ "$DRY_RUN" = "true" ]; then
            warn "apt-get not found; dry-run will continue without package checks."
            return 0
        fi
        warn "apt-get not found; skipping package installation and host hardening."
        return 1
    fi

    info "Installing host security packages"
    run env DEBIAN_FRONTEND=noninteractive apt-get update -y
    run env DEBIAN_FRONTEND=noninteractive apt-get install -y ufw fail2ban unattended-upgrades jq curl

    if [ "$SMTP_MODE" != "none" ]; then
        if command -v debconf-set-selections >/dev/null 2>&1; then
            local mailname
            mailname="${HOST_DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"
            printf 'postfix postfix/main_mailer_type select Internet Site\n' | debconf-set-selections || true
            printf 'postfix postfix/mailname string %s\n' "$mailname" | debconf-set-selections || true
        fi
        run env DEBIAN_FRONTEND=noninteractive apt-get install -y postfix mailutils
    fi
}

write_sysctl() {
    info "Writing reversible sysctl baseline"

    if [ "$DRY_RUN" = "true" ]; then
        note "Would write /etc/sysctl.d/99-sovereign-security.conf"
        return
    fi

    cat > /etc/sysctl.d/99-sovereign-security.conf <<'EOF'
# Sovereign host hardening baseline.
# This file is an imperative MVP artifact, not an authority policy source.
net.ipv4.tcp_syncookies = 1
net.ipv4.conf.all.rp_filter = 1
net.ipv4.conf.default.rp_filter = 1
net.ipv4.conf.all.accept_source_route = 0
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.all.secure_redirects = 0
fs.protected_hardlinks = 1
fs.protected_symlinks = 1
EOF

    sysctl -p /etc/sysctl.d/99-sovereign-security.conf >/dev/null || true
}

ufw_allow_port() {
    local port="$1"
    local proto="$2"
    local comment="$3"
    local cidrs="$4"

    if [ -n "$cidrs" ]; then
        local cidr
        for cidr in $(csv_items "$cidrs"); do
            [ -n "$cidr" ] || continue
            run ufw allow from "$cidr" to any port "$port" proto "$proto" comment "$comment"
        done
        return
    fi

    run ufw allow "$port/$proto" comment "$comment"
}

configure_firewall() {
    local ssh_port="$1"

    if ! command -v ufw >/dev/null 2>&1 && [ "$DRY_RUN" != "true" ]; then
        warn "ufw not found; skipping firewall configuration."
        return
    fi

    info "Configuring UFW without destructive reset"
    run ufw default deny incoming
    run ufw default allow outgoing

    ufw_allow_port "$ssh_port" tcp "Sovereign SSH access" "${SOVEREIGN_ALLOWED_CIDRS:-}"
    ufw_allow_port 80 tcp "HTTP traffic" ""
    ufw_allow_port 443 tcp "HTTPS traffic" ""

    if [ "$APP_PORT" != "80" ] && [ "$APP_PORT" != "443" ]; then
        ufw_allow_port "$APP_PORT" tcp "Sovereign Coolify direct app port" ""
    fi

    if [ -n "$SOKETI_PORT" ]; then
        ufw_allow_port "$SOKETI_PORT" tcp "Sovereign realtime port" ""
    fi

    local exposed_port
    for exposed_port in $(csv_items "$EXPOSED_PORTS"); do
        [ -n "$exposed_port" ] || continue
        if ! is_port_spec "$exposed_port"; then
            warn "Skipping invalid exposed port spec: $exposed_port"
            continue
        fi

        local port proto cidrs
        port="$(port_number "$exposed_port")"
        proto="$(port_proto "$exposed_port")"
        cidrs="$EXPOSED_CIDRS"

        if is_database_port "$exposed_port"; then
            if [ -n "$WIREGUARD_CIDRS" ]; then
                cidrs="$WIREGUARD_CIDRS"
            elif [ -z "$cidrs" ] && [ "$ALLOW_PUBLIC_DATABASE_PORTS" != "true" ]; then
                warn "Refusing global database exposure for $exposed_port. Set SOVEREIGN_WIREGUARD_CIDRS, SOVEREIGN_EXPOSED_CIDRS, or SOVEREIGN_ALLOW_PUBLIC_DATABASE_PORTS=true."
                continue
            fi
        fi

        ufw_allow_port "$port" "$proto" "Sovereign approved exposed port $exposed_port" "$cidrs"
    done

    run ufw --force enable
}

default_public_iface() {
    if [ -n "$PUBLIC_INTERFACE" ]; then
        printf '%s' "$PUBLIC_INTERFACE"
        return
    fi

    if command -v ip >/dev/null 2>&1; then
        ip route get 1.1.1.1 2>/dev/null | awk '{ for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit } }'
    fi
}

iptables_ensure() {
    if [ "$DRY_RUN" = "true" ]; then
        line "${C_DIM}+ iptables -C DOCKER-USER $* || iptables -I DOCKER-USER 1 $*${C_RESET}"
        return 0
    fi

    iptables -C DOCKER-USER "$@" 2>/dev/null || iptables -I DOCKER-USER 1 "$@"
}

write_docker_database_guard_service() {
    local public_iface="$1"
    local cidrs="$2"

    if [ "$DRY_RUN" = "true" ]; then
        note "Would write sovereign-docker-db-guard systemd unit"
        return
    fi

    if ! command -v systemctl >/dev/null 2>&1; then
        return
    fi

    cat > /usr/local/sbin/sovereign-docker-db-guard.sh <<EOF
#!/usr/bin/env bash
set -euo pipefail

PUBLIC_INTERFACE="${public_iface}"
CIDRS="${cidrs}"
DB_PORTS="3306 33060 5432 5433 6379 6380 27017 27018 9200 9300 11211 1433 1521"

if ! command -v iptables >/dev/null 2>&1; then
    exit 0
fi

if ! iptables -nL DOCKER-USER >/dev/null 2>&1; then
    exit 0
fi

ensure_rule() {
    iptables -C DOCKER-USER "\$@" 2>/dev/null || iptables -I DOCKER-USER 1 "\$@"
}

for db_port in \$DB_PORTS; do
    ensure_rule -i "\$PUBLIC_INTERFACE" -p tcp --dport "\$db_port" -m comment --comment "sovereign-db-deny-\$db_port" -j DROP
    for cidr in \$CIDRS; do
        [ -n "\$cidr" ] || continue
        ensure_rule -i "\$PUBLIC_INTERFACE" -p tcp -s "\$cidr" --dport "\$db_port" -m comment --comment "sovereign-db-allow-\$db_port" -j RETURN
    done
done
EOF
    chmod 700 /usr/local/sbin/sovereign-docker-db-guard.sh

    cat > /etc/systemd/system/sovereign-docker-db-guard.service <<'EOF'
[Unit]
Description=Sovereign Docker database ingress guard
After=docker.service
Wants=docker.service

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/sovereign-docker-db-guard.sh
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
EOF

    systemctl daemon-reload
    systemctl enable sovereign-docker-db-guard.service >/dev/null 2>&1 || true
    systemctl start sovereign-docker-db-guard.service >/dev/null 2>&1 || true
}

configure_docker_database_guard() {
    if [ "$ALLOW_PUBLIC_DATABASE_PORTS" = "true" ]; then
        warn "Public database port exposure is explicitly allowed. Docker ingress DB guard will not add deny rules."
        return
    fi

    if ! command -v iptables >/dev/null 2>&1 && [ "$DRY_RUN" != "true" ]; then
        warn "iptables not found; skipping Docker database ingress guard."
        return
    fi

    if [ "$DRY_RUN" != "true" ] && ! iptables -nL DOCKER-USER >/dev/null 2>&1; then
        warn "DOCKER-USER chain not found; skipping Docker database ingress guard."
        return
    fi

    local public_iface
    public_iface="$(default_public_iface)"
    if [ -z "$public_iface" ]; then
        warn "Could not detect public network interface; skipping Docker database ingress guard."
        return
    fi

    info "Protecting Docker-published database ports on ${public_iface}"

    local db_port cidrs cidr
    cidrs="$WIREGUARD_CIDRS"
    if [ -z "$cidrs" ]; then
        cidrs="$EXPOSED_CIDRS"
    fi

    for db_port in 3306 33060 5432 5433 6379 6380 27017 27018 9200 9300 11211 1433 1521; do
        iptables_ensure -i "$public_iface" -p tcp --dport "$db_port" -m comment --comment "sovereign-db-deny-$db_port" -j DROP

        for cidr in $(csv_items "$cidrs"); do
            [ -n "$cidr" ] || continue
            iptables_ensure -i "$public_iface" -p tcp -s "$cidr" --dport "$db_port" -m comment --comment "sovereign-db-allow-$db_port" -j RETURN
        done
    done

    write_docker_database_guard_service "$public_iface" "$cidrs"
}

configure_fail2ban() {
    local ssh_port="$1"

    if ! command -v fail2ban-client >/dev/null 2>&1 && [ ! -d /etc/fail2ban ] && [ "$DRY_RUN" != "true" ]; then
        warn "fail2ban not found; skipping SSH jail."
        return
    fi

    info "Configuring Fail2Ban SSH jail"

    if [ "$DRY_RUN" = "true" ]; then
        note "Would write /etc/fail2ban/jail.d/sovereign-ssh.local"
    else
        mkdir -p /etc/fail2ban/jail.d
        cat > /etc/fail2ban/jail.d/sovereign-ssh.local <<EOF
[sshd]
enabled = true
port = ${ssh_port}
filter = sshd
logpath = /var/log/auth.log
maxretry = 3
findtime = 600
bantime = 3600
EOF
    fi

    if command -v systemctl >/dev/null 2>&1; then
        run systemctl restart fail2ban || true
        run systemctl enable fail2ban || true
    fi
}

configure_unattended_upgrades() {
    if ! command -v systemctl >/dev/null 2>&1; then
        return
    fi

    info "Enabling unattended security upgrades"
    run systemctl enable unattended-upgrades || true
    run systemctl start unattended-upgrades || true
}

configure_docker_logging() {
    info "Applying Docker log rotation defaults"
    run mkdir -p /etc/docker

    if [ "$DRY_RUN" = "true" ]; then
        note "Would merge json-file log rotation into /etc/docker/daemon.json"
        return
    fi

    if [ ! -f /etc/docker/daemon.json ]; then
        cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "json-file",
  "log-opts": {
    "max-size": "10m",
    "max-file": "3"
  }
}
EOF
    else
        cp /etc/docker/daemon.json "/etc/docker/daemon.json.sovereign.$(date +%Y%m%d-%H%M%S).bak"
        jq '. + {"log-driver":"json-file","log-opts":{"max-size":"10m","max-file":"3"}}' \
            /etc/docker/daemon.json > /etc/docker/daemon.json.tmp
        mv /etc/docker/daemon.json.tmp /etc/docker/daemon.json
    fi

    if command -v systemctl >/dev/null 2>&1; then
        systemctl reload docker >/dev/null 2>&1 || systemctl restart docker >/dev/null 2>&1 || true
    fi
}

configure_postfix() {
    if [ "$SMTP_MODE" = "none" ]; then
        return 0
    fi

    if [ "$SMTP_MODE" != "relay" ] && [ "$SMTP_MODE" != "direct" ]; then
        die "Invalid SOVEREIGN_SMTP_MODE=$SMTP_MODE. Use none, relay, or direct."
    fi

    if ! command -v postconf >/dev/null 2>&1 && [ "$DRY_RUN" != "true" ]; then
        warn "postfix not found; skipping SMTP configuration."
        return
    fi

    local docker_cidrs mynetworks host_name
    docker_cidrs="$(detect_docker_cidrs)"
    host_name="${HOST_DOMAIN:-$(hostname -f 2>/dev/null || hostname)}"
    mynetworks="127.0.0.0/8 [::1]/128"

    if [ -n "$docker_cidrs" ]; then
        mynetworks="$mynetworks $docker_cidrs"
    fi

    if [ -n "$WIREGUARD_CIDRS" ]; then
        mynetworks="$mynetworks $(csv_items "$WIREGUARD_CIDRS")"
    fi

    info "Configuring Postfix in $SMTP_MODE mode without public relay"

    run postconf -e "myhostname = $host_name"
    run postconf -e "inet_interfaces = all"
    run postconf -e "mynetworks = $mynetworks"
    run postconf -e "smtpd_relay_restrictions = permit_mynetworks reject_unauth_destination"
    run postconf -e "mydestination = localhost"

    if [ "$SMTP_MODE" = "relay" ]; then
        [ -n "$SMTP_RELAY_HOST" ] || die "SOVEREIGN_SMTP_RELAY_HOST is required for relay mode."
        run postconf -e "relayhost = [$SMTP_RELAY_HOST]:$SMTP_RELAY_PORT"

        if [ -n "$SMTP_RELAY_USERNAME" ] && [ -n "$SMTP_RELAY_PASSWORD" ]; then
            run postconf -e "smtp_sasl_auth_enable = yes"
            run postconf -e "smtp_sasl_password_maps = hash:/etc/postfix/sasl_passwd"
            run postconf -e "smtp_sasl_security_options = noanonymous"
            run postconf -e "smtp_tls_security_level = encrypt"

            if [ "$DRY_RUN" = "true" ]; then
                note "Would write /etc/postfix/sasl_passwd"
            else
                printf '[%s]:%s %s:%s\n' "$SMTP_RELAY_HOST" "$SMTP_RELAY_PORT" "$SMTP_RELAY_USERNAME" "$SMTP_RELAY_PASSWORD" > /etc/postfix/sasl_passwd
                chmod 600 /etc/postfix/sasl_passwd
                postmap /etc/postfix/sasl_passwd
            fi
        fi
    else
        warn "Direct SMTP mode requires valid PTR/rDNS, SPF, DKIM, DMARC, and provider support for outbound port 25."
        run postconf -e "relayhost ="
    fi

    if command -v systemctl >/dev/null 2>&1; then
        run systemctl restart postfix || true
        run systemctl enable postfix || true
    fi

    if [ -n "$SMTP_TEST_RECIPIENT" ] && command -v mail >/dev/null 2>&1; then
        if [ "$DRY_RUN" = "true" ]; then
            note "Would send SMTP test mail to $SMTP_TEST_RECIPIENT"
        else
            printf 'Sovereign SMTP test from %s\n' "$host_name" | mail -s "Sovereign SMTP test" "$SMTP_TEST_RECIPIENT" || true
        fi
    fi
}

write_marker() {
    info "Writing hardening marker"

    if [ "$DRY_RUN" = "true" ]; then
        note "Would write $MARKER_FILE"
        return
    fi

    mkdir -p "$(dirname "$MARKER_FILE")"
    cat > "$MARKER_FILE" <<EOF
{
  "version": "${HARDENING_VERSION}",
  "profile": "${PROFILE}",
  "ssh_port": "$(detect_ssh_port)",
  "app_port": "${APP_PORT}",
  "soketi_port": "${SOKETI_PORT}",
  "exposed_ports": "${EXPOSED_PORTS}",
  "exposed_cidrs": "${EXPOSED_CIDRS}",
  "wireguard_cidrs": "${WIREGUARD_CIDRS}",
  "smtp_mode": "${SMTP_MODE}",
  "host_domain": "${HOST_DOMAIN}",
  "host_public_ip": "$(detect_public_ip)",
  "updated_at": "$(date -Iseconds)"
}
EOF
}

main() {
    if [ "$EUID" -ne 0 ] && [ "$DRY_RUN" != "true" ]; then
        die "Please run this script as root or with sudo."
    fi

    case "$PROFILE" in
        baseline|strict) ;;
        *) die "Invalid SOVEREIGN_HARDENING_PROFILE=$PROFILE. Use baseline or strict." ;;
    esac

    local ssh_port
    ssh_port="$(detect_ssh_port)"

    line "${C_MAGENTA}============================================================${C_RESET}"
    line "${C_CYAN}  SOVEREIGN HOST HARDENING // MVP EXECUTION SUBSTRATE${C_RESET}"
    line "${C_MAGENTA}============================================================${C_RESET}"
    note "Profile: $PROFILE"
    note "Dry run: $DRY_RUN"
    note "SSH port: $ssh_port"
    note "APP port: $APP_PORT"
    note "Soketi port: $SOKETI_PORT"

    local published
    published="$(detect_published_ports)"
    if [ -n "$published" ]; then
        info "Detected Docker published ports"
        printf '%s\n' "$published"
    fi

    if [ "$PROFILE" = "strict" ] && [ -n "$EXPOSED_PORTS" ] && [ -z "$WIREGUARD_CIDRS" ] && [ -z "$EXPOSED_CIDRS" ] && [ "$ALLOW_PUBLIC_DATABASE_PORTS" != "true" ]; then
        warn "Strict profile has exposed ports without CIDR policy. Database ports will not be globally opened."
    fi

    install_packages || {
        warn "Host hardening skipped because this host is not apt-compatible."
        exit 0
    }

    write_sysctl
    configure_firewall "$ssh_port"
    configure_docker_database_guard
    configure_fail2ban "$ssh_port"
    configure_unattended_upgrades
    configure_docker_logging
    configure_postfix
    write_marker

    line "${C_GREEN}Sovereign host hardening complete.${C_RESET}"
}

main "$@"
