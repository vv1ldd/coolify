# Sovereign disk encryption (LUKS)

Optional full-disk encryption before Sovereign Coolify runtime converge.

## Flow

```text
plain cloud SSH  →  install-sovereign.sh (SOVEREIGN_DISK_ENCRYPT=auto)
                        →  kexec + Ubuntu autoinstall (LUKS LVM)
                        →  reboot (encrypted root)
                        →  sovereign-firstboot  →  install-sovereign.sh
```

Rescue fallback: `SOVEREIGN_RESCUE_PREPARE=true` + `sovereign-disk-prepare.sh`.

## Install from git (recommended)

```bash
export SOVEREIGN_DISK_ENCRYPT=auto
export SOVEREIGN_LUKS_PASSPHRASE_FILE=/root/.sovereign-luks-passphrase
export SOVEREIGN_RUNTIME_CONVERGE_OWNER=true
export SOVEREIGN_ASSUME_YES=true

git clone --depth 1 -b sovereign https://github.com/vv1ldd/coolify.git /tmp/coolify-sovereign
bash /tmp/coolify-sovereign/scripts/install-sovereign.sh
```

Or one curl that clones then installs:

```bash
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/bootstrap-sovereign-from-git.sh \
  -o /tmp/bootstrap-sovereign-from-git.sh
bash /tmp/bootstrap-sovereign-from-git.sh
```

## Env

| Variable | Meaning |
|----------|---------|
| `SOVEREIGN_DISK_ENCRYPT` | `false` (default), `true`, `auto`, `reboot` |
| `SOVEREIGN_RESCUE_PREPARE` | `true` — allow wipe in rescue only |
| `SOVEREIGN_LUKS_PASSPHRASE_FILE` | Root-readable passphrase for kexec/rescue |
| `SOVEREIGN_UBUNTU_MIRROR` | APT/debootstrap mirror (default Selectel) |
| `SOVEREIGN_NETBOOT_BASE` | Netboot dir with `linux` + `initrd` (default: `releases.ubuntu.com/noble/netboot/amd64`) |
| `SOVEREIGN_ISO_URL` | Live server ISO for netboot mini-initrd (default: Ubuntu 24.04.4 live-server amd64) |
| `SOVEREIGN_TARGET_DISK` | Default `/dev/sda` |
| `SOVEREIGN_AUTOCONVERGE_AFTER_ENCRYPT` | Run Coolify after encrypted first boot (default `true`) |
