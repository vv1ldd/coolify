#!/usr/bin/env bash
# Rescue-phase full-disk LUKS + Ubuntu 24.04 (noble) debootstrap.
# Intended to run BEFORE sovereign runtime converge on a wiped host.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=sovereign-disk-lib.sh
source "${SCRIPT_DIR}/sovereign-disk-lib.sh"

TARGET_DISK="${SOVEREIGN_TARGET_DISK:-/dev/sda}"
LUKS_NAME="${SOVEREIGN_LUKS_NAME:-cryptroot}"
VG_NAME="${SOVEREIGN_LVM_VG:-ubuntu-vg}"
LV_NAME="${SOVEREIGN_LVM_LV:-root}"
MNT=/mnt
RELEASE=noble
ARCH=amd64
MIRROR="${SOVEREIGN_DEBOOTSTRAP_MIRROR:-http://mirror.selectel.ru/ubuntu}"

log() { printf '[disk-prepare] %s\n' "$*"; }
die() { printf '[disk-prepare] ERROR: %s\n' "$*" >&2; exit 1; }

require_root() {
    [ "$(id -u)" -eq 0 ] || die "run as root"
}

require_rescue() {
    if ! sovereign_disk_is_rescue_env; then
        die "refusing to wipe disk outside rescue (set SOVEREIGN_RESCUE_PREPARE=true only in rescue)"
    fi
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
        read -rsp 'LUKS passphrase: ' _pass1; echo >&2
        read -rsp 'LUKS passphrase (confirm): ' _pass2; echo >&2
        [ "$_pass1" = "$_pass2" ] || die "passphrases do not match"
        [ -n "$_pass1" ] || die "empty passphrase"
        printf '%s' "$_pass1"
        return 0
    fi
    die "set SOVEREIGN_LUKS_PASSPHRASE_FILE or SOVEREIGN_LUKS_PASSPHRASE for non-interactive rescue"
}

install_host_packages() {
    if command -v apt-get >/dev/null 2>&1; then
        export DEBIAN_FRONTEND=noninteractive
        apt-get update -qq
        apt-get install -y -qq debootstrap cryptsetup lvm2 e2fsprogs parted gdisk grub-pc grub-efi-amd64-signed shim-signed dosfstools rsync
    fi
}

wipe_and_partition() {
    log "wiping ${TARGET_DISK}"
    wipefs -a "$TARGET_DISK"
    if [ -d /sys/firmware/efi ]; then
        parted -s "$TARGET_DISK" mklabel gpt
        parted -s "$TARGET_DISK" mkpart ESP fat32 1MiB 512MiB
        parted -s "$TARGET_DISK" set 1 esp on
        parted -s "$TARGET_DISK" mkpart primary 512MiB 100%
        partprobe "$TARGET_DISK"
        mkfs.vfat -F32 "${TARGET_DISK}1"
        LUKS_PART="${TARGET_DISK}2"
    else
        parted -s "$TARGET_DISK" mklabel gpt
        parted -s "$TARGET_DISK" mkpart primary 1MiB 100%
        partprobe "$TARGET_DISK"
        LUKS_PART="${TARGET_DISK}1"
    fi
    export LUKS_PART
}

setup_luks_lvm() {
    local passphrase
    passphrase="$(read_passphrase)"
    log "formatting LUKS on ${LUKS_PART}"
    printf '%s' "$passphrase" | cryptsetup -q luksFormat "$LUKS_PART" -
    printf '%s' "$passphrase" | cryptsetup open "$LUKS_PART" "$LUKS_NAME" -
    pvcreate -ff -y "/dev/mapper/${LUKS_NAME}"
    vgcreate "$VG_NAME" "/dev/mapper/${LUKS_NAME}"
    lvcreate -l 100%FREE -n "$LV_NAME" "$VG_NAME"
    mkfs.ext4 -L sovereign-root "/dev/${VG_NAME}/${LV_NAME}"
}

mount_target() {
    mount "/dev/${VG_NAME}/${LV_NAME}" "$MNT"
    if [ -d /sys/firmware/efi ]; then
        mkdir -p "${MNT}/boot/efi"
        mount "${TARGET_DISK}1" "${MNT}/boot/efi"
    fi
}

run_debootstrap() {
    log "debootstrap ${RELEASE} from ${MIRROR}"
    debootstrap --arch="$ARCH" --variant=minbase "$RELEASE" "$MNT" "$MIRROR"
}

write_fstab_crypttab() {
    local uuid mapper_uuid
    uuid="$(blkid -s UUID -o value "/dev/${VG_NAME}/${LV_NAME}")"
    mapper_uuid="$(blkid -s UUID -o value "${LUKS_PART}")"

    cat >"${MNT}/etc/crypttab" <<EOF
${LUKS_NAME} UUID=${mapper_uuid} none luks,discard
EOF

    if [ -d /sys/firmware/efi ]; then
        local esp_uuid
        esp_uuid="$(blkid -s UUID -o value "${TARGET_DISK}1")"
        cat >"${MNT}/etc/fstab" <<EOF
UUID=${uuid} / ext4 defaults 0 1
UUID=${esp_uuid} /boot/efi vfat defaults 0 1
EOF
    else
        cat >"${MNT}/etc/fstab" <<EOF
UUID=${uuid} / ext4 defaults 0 1
EOF
    fi
}

copy_rescue_ssh_keys() {
    if [ -d /root/.ssh ]; then
        mkdir -p "${MNT}/root/.ssh"
        chmod 700 "${MNT}/root/.ssh"
        if [ -f /root/.ssh/authorized_keys ]; then
            cp /root/.ssh/authorized_keys "${MNT}/root/.ssh/authorized_keys"
            chmod 600 "${MNT}/root/.ssh/authorized_keys"
        fi
    fi
}

chroot_bootstrap() {
  log "configuring target system in chroot"
  local luks_part_uuid
  luks_part_uuid="$(blkid -s UUID -o value "${LUKS_PART}")"

  mount --bind /dev "${MNT}/dev"
  mount --bind /dev/pts "${MNT}/dev/pts"
  mount --bind /proc "${MNT}/proc"
  mount --bind /sys "${MNT}/sys"
  if [ -d /sys/firmware/efi ]; then
      mount --bind /sys/firmware/efi "${MNT}/sys/firmware/efi"
  fi

  chroot "$MNT" /bin/bash -euo pipefail <<CHROOT
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq linux-image-generic cryptsetup-initramfs lvm2 openssh-server sudo curl ca-certificates gnupg
echo "priya" > /etc/hostname
printf "127.0.1.1 priya\n" >> /etc/hosts
if [ -f /etc/ssh/sshd_config ]; then
    sed -i 's/^#\?PasswordAuthentication.*/PasswordAuthentication no/' /etc/ssh/sshd_config || true
fi
systemctl enable ssh

# initramfs must know how to unlock root
echo "CRYPTSETUP=y" > /etc/cryptsetup-initramfs/conf-hook
update-initramfs -u -k all

if [ -d /sys/firmware/efi ]; then
    grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=ubuntu --recheck
else
    grub-install ${TARGET_DISK}
fi
update-grub
CHROOT

  umount "${MNT}/sys/firmware/efi" 2>/dev/null || true
  umount "${MNT}/sys" "${MNT}/proc" "${MNT}/dev/pts" "${MNT}/dev"
}

finish() {
    sync
    umount -R "$MNT" 2>/dev/null || true
    cryptsetup close "$LUKS_NAME" 2>/dev/null || true
    log "disk prepare complete — reboot and unlock LUKS in provider console"
    log "then re-run install with SOVEREIGN_DISK_ENCRYPT=true (without SOVEREIGN_RESCUE_PREPARE)"
}

main() {
    require_root
    require_rescue
    [ -b "$TARGET_DISK" ] || die "missing block device ${TARGET_DISK}"

    if [ "${SOVEREIGN_ASSUME_YES:-false}" != 'true' ] && [ -t 0 ]; then
        printf 'This will ERASE %s and install encrypted Ubuntu 24.04. Continue? [y/N]: ' "$TARGET_DISK"
        read -r confirm
        case "$confirm" in
            y|Y|yes|YES) ;;
            *) die "aborted" ;;
        esac
    fi

    install_host_packages
    wipe_and_partition
    setup_luks_lvm
    mount_target
    run_debootstrap
    write_fstab_crypttab
    copy_rescue_ssh_keys
    chroot_bootstrap
    finish
}

main "$@"
