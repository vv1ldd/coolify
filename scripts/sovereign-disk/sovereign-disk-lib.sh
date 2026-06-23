#!/usr/bin/env bash
# Shared disk encryption helpers for Sovereign Coolify bootstrap.
# Sourced by scripts/install-sovereign.sh and sovereign-disk-prepare.sh.

set -euo pipefail

sovereign_disk_root_source() {
    findmnt -n -o SOURCE / 2>/dev/null || true
}

sovereign_disk_is_luks() {
    local source fstype
    source="$(sovereign_disk_root_source)"
    [ -n "$source" ] || return 1

    case "$source" in
        /dev/mapper/* | /dev/dm-*)
            return 0
            ;;
    esac

    fstype="$(findmnt -n -o FSTYPE / 2>/dev/null || true)"
    [ "$fstype" = 'crypto_LUKS' ] && return 0

    return 1
}

sovereign_disk_primary_block() {
    local source disk
    source="$(sovereign_disk_root_source)"
    source="${source#/dev/}"
    source="${source%%[*}"
    if [[ "$source" == mapper/* ]]; then
        disk="$(lsblk -no PKNAME "/dev/${source}" 2>/dev/null | head -n1)"
        if [ -n "$disk" ]; then
            printf '/dev/%s' "$disk"
            return 0
        fi
    fi
    disk="$(lsblk -no PKNAME / 2>/dev/null | head -n1)"
    if [ -n "$disk" ]; then
        printf '/dev/%s' "$disk"
        return 0
    fi
    printf '%s' '/dev/sda'
}

sovereign_disk_is_plain_cloud_image() {
    sovereign_disk_is_luks && return 1
    local source
    source="$(sovereign_disk_root_source)"
    [[ "$source" =~ ^/dev/(sd|vd|nvme) ]] || return 1
    local label
    label="$(lsblk -no LABEL "$source" 2>/dev/null | head -n1)"
    [ "$label" = 'cloudimg-rootfs' ] || lsblk -no FSTYPE "$source" 2>/dev/null | grep -qx ext4
}

sovereign_disk_is_rescue_env() {
    if [ "${SOVEREIGN_RESCUE_PREPARE:-false}" = 'true' ]; then
        return 0
    fi

    local root_source
    root_source="$(sovereign_disk_root_source)"
    if [[ "$root_source" == *overlay* ]] || [[ "$root_source" == *tmpfs* ]]; then
        return 0
    fi

    if [ -d /run/archiso ] || [ -f /.rescueloaded ] || [ -d /run/live ]; then
        return 0
    fi

    # Selectel / generic rescue: root is not the primary data disk partition.
    if [ -b /dev/sda1 ] && ! findmnt -n /dev/sda1 >/dev/null 2>&1; then
        if findmnt -n / | grep -qE 'overlay|tmpfs'; then
            return 0
        fi
    fi

    return 1
}

sovereign_disk_gate_message() {
    cat <<'EOF'
Disk encryption is required but root is not LUKS yet.

Next steps (Selectel and similar cloud providers):
  1. Panel → server → Rescue / recovery mode → reboot into rescue.
  2. SSH or web console into rescue.
  3. Run the same install command with:
       SOVEREIGN_RESCUE_PREPARE=true
       SOVEREIGN_DISK_ENCRYPT=true
       SOVEREIGN_RUNTIME_CONVERGE_OWNER=true
     Optional passphrase file (root-only):
       SOVEREIGN_LUKS_PASSPHRASE_FILE=/root/.sovereign-luks-passphrase
  4. After reboot, unlock LUKS in the provider console if prompted.
  5. Re-run install (without SOVEREIGN_RESCUE_PREPARE) to converge Sovereign Coolify.

Plain cloud images (cloudimg-rootfs) cannot be encrypted in-place from a running root filesystem.
EOF
}
