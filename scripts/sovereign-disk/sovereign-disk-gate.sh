#!/usr/bin/env bash
# Pre-flight gate for Sovereign install — call before runtime converge.

sovereign_disk_gate() {
    case "${SOVEREIGN_DISK_ENCRYPT:-false}" in
        true|auto|1|yes|reboot)
            ;;
        *)
            return 0
            ;;
    esac

    local lib_dir
    lib_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    # shellcheck source=sovereign-disk-lib.sh
    source "${lib_dir}/sovereign-disk-lib.sh"

    if sovereign_disk_is_luks; then
        echo "[disk] encrypted root detected: $(sovereign_disk_root_source)"
        return 0
    fi

    if sovereign_disk_is_rescue_env; then
        local prepare="${SOVEREIGN_DISK_PREPARE_SCRIPT:-${lib_dir}/sovereign-disk-prepare.sh}"
        chmod +x "$prepare" 2>/dev/null || true
        [ -f "$prepare" ] || { echo "[disk] missing ${prepare}" >&2; exit 1; }
        echo "[disk] rescue → encrypted disk prepare"
        bash "$prepare"
        exit 0
    fi

    # Plain cloud image: one curl → kexec autoinstall → reboot (default for auto/reboot)
    if sovereign_disk_is_plain_cloud_image || [ "${SOVEREIGN_DISK_ENCRYPT:-}" = 'reboot' ] || [ "${SOVEREIGN_DISK_ENCRYPT:-}" = 'auto' ]; then
        local kexec="${SOVEREIGN_DISK_KEXEC_SCRIPT:-${lib_dir}/sovereign-disk-kexec-autoinstall.sh}"
        chmod +x "$kexec" 2>/dev/null || true
        [ -f "$kexec" ] || { echo "[disk] missing ${kexec}" >&2; exit 1; }
        echo "[disk] plain cloud image → kexec encrypted autoinstall (next: automatic reboot)"
        bash "$kexec"
        exit 0
    fi

    sovereign_disk_gate_message >&2
    exit 1
}
