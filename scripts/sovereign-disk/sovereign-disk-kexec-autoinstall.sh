#!/usr/bin/env bash
# One-shot from a running plain cloud image:
#   kexec → Ubuntu autoinstall (LUKS LVM) → reboot → encrypted root (+ optional sovereign firstboot).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=sovereign-disk-lib.sh
source "${SCRIPT_DIR}/sovereign-disk-lib.sh"
# shellcheck source=sovereign-disk-network.sh
source "${SCRIPT_DIR}/sovereign-disk-network.sh"

STAGING="${SOVEREIGN_KEXEC_STAGING:-/var/tmp/sovereign-kexec}"
MIRROR="${SOVEREIGN_UBUNTU_MIRROR:-http://mirror.selectel.ru/ubuntu}"
NETBOOT_BASE="${SOVEREIGN_NETBOOT_BASE:-}"
ISO_URL="${SOVEREIGN_ISO_URL:-https://releases.ubuntu.com/24.04.4/ubuntu-24.04.4-live-server-amd64.iso}"
HOSTNAME="${SOVEREIGN_HOSTNAME:-priya}"
ADMIN_USER="${SOVEREIGN_ADMIN_USER:-sovereign}"

log() { printf '[disk-kexec] %s\n' "$*"; }
die() { printf '[disk-kexec] ERROR: %s\n' "$*" >&2; exit 1; }

resolve_netboot_base() {
    if [ -n "$NETBOOT_BASE" ]; then
        printf '%s' "$NETBOOT_BASE"
        return 0
    fi

    local candidate
    for candidate in \
        "http://releases.ubuntu.com/noble/netboot/amd64" \
        "${MIRROR}/dists/noble/main/installer-amd64/current/images/netboot/ubuntu-installer/amd64"; do
        if curl -fsI "${candidate}/linux" >/dev/null 2>&1; then
            NETBOOT_BASE="$candidate"
            printf '%s' "$candidate"
            return 0
        fi
    done

    die "no netboot files found; set SOVEREIGN_NETBOOT_BASE=http://releases.ubuntu.com/noble/netboot/amd64"
}

resolve_netboot_initrd_name() {
    local base="$1"

    if curl -fsI "${base}/initrd" >/dev/null 2>&1; then
        printf 'initrd'
        return 0
    fi

    if curl -fsI "${base}/initrd.gz" >/dev/null 2>&1; then
        printf 'initrd.gz'
        return 0
    fi

    die "no initrd at ${base}"
}

read_passphrase() {
    if [ -n "${SOVEREIGN_LUKS_PASSPHRASE:-}" ]; then
        printf '%s' "$SOVEREIGN_LUKS_PASSPHRASE"
        return 0
    fi
    if [ -n "${SOVEREIGN_LUKS_PASSPHRASE_FILE:-}" ] && [ -r "${SOVEREIGN_LUKS_PASSPHRASE_FILE}" ]; then
        cat "${SOVEREIGN_LUKS_PASSPHRASE_FILE}"
        return 0
    fi
    if [ -t 0 ]; then
        read -rsp 'LUKS passphrase for new encrypted disk: ' _p1; echo >&2
        read -rsp 'Confirm passphrase: ' _p2; echo >&2
        [ "$_p1" = "$_p2" ] || die "passphrases do not match"
        [ -n "$_p1" ] || die "empty passphrase"
        printf '%s' "$_p1"
        return 0
    fi
    die "set SOVEREIGN_LUKS_PASSPHRASE or SOVEREIGN_LUKS_PASSPHRASE_FILE"
}

collect_ssh_keys() {
    local keyfile="${SOVEREIGN_SSH_AUTHORIZED_KEYS:-/root/.ssh/authorized_keys}"
    if [ -f "$keyfile" ]; then
        awk 'NF && $1 ~ /^(ssh-|ecdsa-|sk-)/ { print }' "$keyfile"
        return 0
    fi
    die "no SSH authorized_keys at ${keyfile} — add a key before encrypting"
}

install_kexec_tools() {
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq kexec-tools curl cpio gzip openssl
}

download_netboot() {
    local base initrd_name
    base="$(resolve_netboot_base)"
    initrd_name="$(resolve_netboot_initrd_name "$base")"
    log "netboot base: ${base} (${initrd_name})"
    mkdir -p "${STAGING}/nocloud"
    curl -fsSL "${base}/linux" -o "${STAGING}/vmlinuz"
    curl -fsSL "${base}/${initrd_name}" -o "${STAGING}/initrd.gz.orig"
}

firstboot_env_block() {
    cat <<EOF
SOVEREIGN_REPOSITORY=${SOVEREIGN_REPOSITORY:-vv1ldd/coolify}
SOVEREIGN_BRANCH=${SOVEREIGN_BRANCH:-sovereign}
SOVEREIGN_RUNTIME_CONVERGE_OWNER=${SOVEREIGN_RUNTIME_CONVERGE_OWNER:-true}
SOVEREIGN_ASSUME_YES=${SOVEREIGN_ASSUME_YES:-true}
SOVEREIGN_DISK_ENCRYPT=true
SOVEREIGN_HOST_DOMAIN=${SOVEREIGN_HOST_DOMAIN:-}
SIMPLE_L1_DOMAIN=${SIMPLE_L1_DOMAIN:-}
SIMPLE_L1_ISSUER_URL=${SIMPLE_L1_ISSUER_URL:-}
SL1_CONNECT_ISSUER=${SL1_CONNECT_ISSUER:-}
SL1_CONNECT_CLIENT_ID=${SL1_CONNECT_CLIENT_ID:-}
SIMPLE_L1_PUBLIC_IP=${SIMPLE_L1_PUBLIC_IP:-}
EOF
}

write_nocloud() {
    local passphrase keys_yaml pass_escaped locked_hash late_commands network_yaml
    passphrase="$(read_passphrase)"
    keys_yaml="$(collect_ssh_keys | sed 's/^/      - /')"
    pass_escaped="$(printf '%s' "$passphrase" | sed 's/\\/\\\\/g; s/"/\\"/g')"
    locked_hash="$(openssl passwd -6 -salt sovereign "$(openssl rand -hex 16)")"
    network_yaml="$(sovereign_capture_network_yaml)"

    log "network capture: $(sovereign_network_summary)"

    cat >"${STAGING}/nocloud/meta-data" <<EOF
instance-id: sovereign-disk-$(date +%s)
EOF

    if [ "${SOVEREIGN_AUTOCONVERGE_AFTER_ENCRYPT:-true}" = 'true' ]; then
        late_commands="$(cat <<LATE
  late-commands:
    - curtin in-target -- mkdir -p /root/.ssh /etc/sovereign
    - curtin in-target -- bash -c 'cp /home/${ADMIN_USER}/.ssh/authorized_keys /root/.ssh/authorized_keys && chmod 700 /root/.ssh && chmod 600 /root/.ssh/authorized_keys'
    - curtin in-target -- bash -c 'cat > /etc/sovereign/firstboot.env <<ENVEOF
$(firstboot_env_block)
ENVEOF'
    - curtin in-target -- bash -c 'echo ready > /etc/sovereign-disk-ready'
    - curtin in-target -- curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/sovereign-disk/sovereign-firstboot.sh -o /usr/local/sbin/sovereign-firstboot
    - curtin in-target -- chmod +x /usr/local/sbin/sovereign-firstboot
    - curtin in-target -- bash -c 'cat > /etc/systemd/system/sovereign-firstboot.service <<UNIT
[Unit]
Description=Sovereign Coolify first boot converge
ConditionPathExists=/etc/sovereign-disk-ready
After=network-online.target
Wants=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/sovereign-firstboot
RemainAfterExit=yes

[Install]
WantedBy=multi-user.target
UNIT'
    - curtin in-target -- systemctl enable sovereign-firstboot.service
LATE
)"
    else
        late_commands="  late-commands: []"
    fi

    cat >"${STAGING}/nocloud/user-data" <<EOF
#cloud-config
autoinstall:
  version: 1
  locale: en_US.UTF-8
  keyboard:
    layout: us
  identity:
    hostname: ${HOSTNAME}
    username: ${ADMIN_USER}
    password: "${locked_hash}"
  ssh:
    install-server: true
    allow-pw: false
    authorized-keys:
${keys_yaml}
${network_yaml}
  storage:
    layout:
      name: lvm
      match:
        size: largest
      password: "${pass_escaped}"
  packages:
    - curl
    - ca-certificates
    - git
${late_commands}
EOF
}

embed_nocloud_into_initrd() {
    local nocloud_cpio="${STAGING}/nocloud.cpio.gz"
    (
        cd "${STAGING}/nocloud"
        find . -mindepth 1 -maxdepth 1 -print0 | cpio --null -o --format=newc
    ) | gzip -9 >"$nocloud_cpio"
    cat "${STAGING}/initrd.gz.orig" "$nocloud_cpio" >"${STAGING}/initrd.gz"
}

kexec_reboot() {
    local kernel_network
    kernel_network="$(sovereign_kexec_kernel_network_append)"

    log "kexec into Ubuntu 24.04 autoinstall — primary disk will be wiped"
    log "installer runs headless; then reboot to LUKS root (unlock in provider console if prompted)"
    log "kernel network: ${kernel_network}"
    log "live-server iso-url: ${ISO_URL} (multi-GB download during install)"
    sleep 3

    # Noble netboot mini-initrd requires iso-url (see releases.ubuntu.com/noble/netboot/amd64/pxelinux.cfg/default).
    kexec -l "${STAGING}/vmlinuz" \
        --initrd="${STAGING}/initrd.gz" \
        --append="autoinstall ds=nocloud root=/dev/ram0 ramdisk_size=1500000 ${kernel_network} iso-url=${ISO_URL} --- quiet"

    sync
    systemctl kexec 2>/dev/null || kexec -e
}

main() {
    [ "$(id -u)" -eq 0 ] || die "run as root"
    sovereign_disk_is_luks && die "disk is already LUKS"
    sovereign_disk_is_rescue_env && die "already in rescue — use sovereign-disk-prepare.sh instead"

    if [ "${SOVEREIGN_ASSUME_YES:-false}" != 'true' ] && [ -t 0 ]; then
        printf 'Wipe disk and reinstall encrypted Ubuntu 24.04 via kexec? [y/N]: '
        read -r confirm
        case "$confirm" in
            y|Y|yes|YES) ;;
            *) die "aborted" ;;
        esac
    fi

    install_kexec_tools
    download_netboot
    write_nocloud
    embed_nocloud_into_initrd
    kexec_reboot
}

main "$@"
