# Sovereign Coolify VPS Install

This fork keeps the upstream Coolify installer untouched and adds a separate
Sovereign install path for VPS testing.

## What This Installs

- Coolify application image: `ghcr.io/vv1ldd/coolify:sovereign`
- Upstream realtime image: `ghcr.io/coollabsio/coolify-realtime:1.0.13`
- Postgres 15 and Redis 7 from the standard Coolify compose files
- Persistent state under `/data/coolify`
- App port: `8000` by default
- Realtime port: `6001` by default
- SL1-only authentication through `https://simplel1.online` by default

## Before Installing

1. Push the `sovereign` branch.
2. Wait for the `Sovereign Build` GitHub Action to publish:

   ```bash
   ghcr.io/vv1ldd/coolify:sovereign
   ```

3. Make the GHCR package public, or pass a GitHub token during install:

   ```bash
   export GHCR_USERNAME=vv1ldd
   export GHCR_TOKEN=github_pat_or_classic_pat_with_read_packages
   ```

## One Command Bootstrap

Run as `root` or with `sudo`.

```bash
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo bash
```

The bootstrap script auto-detects the host state and shows an interactive
cyberpunk terminal menu when a TTY is available:

- clean VPS -> fresh Sovereign Coolify install
- existing upstream Coolify -> preserve data, upgrade to Sovereign, generate an
  SL1 admin claim URL
- existing Sovereign Coolify -> refresh compose/image and restart containers

For non-interactive use, set `SOVEREIGN_INSTALL_MODE` to `auto`, `fresh`,
`upgrade`, or `refresh`.

Optional canonical host domain:

```bash
export SOVEREIGN_HOST_DOMAIN=coolify.example.com
export SOVEREIGN_APP_SCHEME=https
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

When a canonical host domain is provided, the installer syncs:

- `APP_URL`
- `SOVEREIGN_PANEL_URL`
- `SOVEREIGN_HOST_DOMAIN`
- `SOVEREIGN_HOST_URL`
- Coolify instance settings URL (`instance_settings.fqdn`)

This keeps the visible panel URL, host identity URL, SL1 callback base, and
Coolify proxy configuration aligned.

Optional pre-created root user:

```bash
export ROOT_USERNAME=admin
export ROOT_USER_EMAIL=admin@example.com
export ROOT_USER_PASSWORD='change-this-password'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Optional custom ports/image:

```bash
export APP_PORT=8000
export SOKETI_PORT=6001
export COOLIFY_IMAGE=ghcr.io/vv1ldd/coolify:sovereign
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Optional SL1 Connect settings:

```bash
export SL1_CONNECT_ISSUER=https://simplel1.online
export SL1_CONNECT_CLIENT_ID=coolify.sovereign
export SL1_CONNECT_CLIENT_NAME='Sovereign Coolify'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Optional host hardening:

```bash
export SOVEREIGN_HARDENING=true
export SOVEREIGN_HARDENING_PROFILE=baseline
export SOVEREIGN_WIREGUARD_CIDRS=10.8.0.0/24
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Database/container exposed ports are closed by default unless explicitly allowed.
Prefer WireGuard/VPN-scoped access:

```bash
export SOVEREIGN_HARDENING=true
export SOVEREIGN_EXPOSED_PORTS=3306/tcp,5432/tcp
export SOVEREIGN_WIREGUARD_CIDRS=10.8.0.0/24
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Optional outbound SMTP relay on the host:

```bash
export SOVEREIGN_HARDENING=true
export SOVEREIGN_SMTP_MODE=relay
export SOVEREIGN_SMTP_RELAY_HOST=smtp.example.com
export SOVEREIGN_SMTP_RELAY_PORT=587
export SOVEREIGN_SMTP_RELAY_USERNAME=postmaster@example.com
export SOVEREIGN_SMTP_RELAY_PASSWORD='smtp-password'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

The hardening script never creates an open SMTP relay and does not globally open
database ports unless explicitly configured as a break-glass exception. It also
adds a `DOCKER-USER` ingress guard so Docker-published sensitive ports do not
bypass UFW on the public interface.

When `SOVEREIGN_HOST_DOMAIN` is configured, direct public access to `APP_PORT`
is blocked by default because the panel should be reached through the canonical
domain on `443`. Break-glass exposure is explicit:

```bash
export SOVEREIGN_ALLOW_DIRECT_APP_PORT=true
```

The internal Soketi metrics/control port `6002` is also blocked on the public
interface by default. Only expose it intentionally:

```bash
export SOVEREIGN_ALLOW_PUBLIC_SOKETI_METRICS=true
```

## TLS Certificates

Sovereign Coolify uses the normal Coolify proxy flow. When the local server runs
Traefik and the instance URL is set to `https://your-domain`, Coolify writes a
dynamic Traefik configuration for the panel host. The HTTPS routers use
`tls.certresolver=letsencrypt`, so Traefik requests and renews the certificate
from Let's Encrypt.

Requirements:

- DNS `A/AAAA` for the panel domain points to the VPS.
- Public ports `80` and `443` reach the Coolify proxy.
- The domain is not blocked by Cloudflare proxy mode unless the DNS/proxy setup
  is intentionally configured for that flow.
- The installer has synced `APP_URL` and `instance_settings.fqdn` to the same
  canonical `https://` URL.

After installation, open:

```text
http://SERVER_IP:8000
```

## Upgrade Or Refresh The Fork

After pushing a new `sovereign` image:

```bash
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo bash
```

If you are already on the VPS inside a checkout of this repository, use the
repository update script:

```bash
sudo bash scripts/update-sovereign-from-repo.sh
```

By default it:

- copies runtime compose/env scripts into `/data/coolify/source`
- backs up `/data/coolify/source/.env`
- pulls the configured Sovereign image
- restarts the Coolify runtime
- runs database migrations
- rebuilds Laravel caches
- checks `/api/health`
- runs `dns:steering:evaluate --json` as a dry run

For emergency DNS failover through configured DNS steering policies:

```bash
sudo SOVEREIGN_DNS_STEERING=apply bash scripts/update-sovereign-from-repo.sh
```

To skip DNS steering completely:

```bash
sudo SOVEREIGN_DNS_STEERING=skip bash scripts/update-sovereign-from-repo.sh
```

Update rail:

1. Commit code to branch `sovereign`.
2. Push `origin sovereign`.
3. Wait for `Sovereign Build` to publish `ghcr.io/vv1ldd/coolify:sovereign`.
4. Run the one command bootstrap on the VPS and select refresh or upgrade.
5. Verify `/api/health` and one SL1 login round-trip.

## Files Added For The Fork

- `scripts/install-sovereign.sh` - unified cyberpunk bootstrap for fresh install,
  upstream upgrade, and Sovereign refresh
- `scripts/upgrade-sovereign.sh` - internal worker that pulls the fork image and
  restarts compose
- `scripts/update-sovereign-from-repo.sh` - operator update rail for running a
  refresh directly from a checked-out repository on the VPS
- `scripts/sovereign-host-hardening.sh` - optional reversible host hardening
  worker for firewall, Fail2Ban, Docker logs, SMTP relay, and network surfaces
- `docker-compose.sovereign.prod.yml` - overrides the app image to the fork image
- `.github/workflows/sovereign-build.yml` - publishes `ghcr.io/vv1ldd/coolify:sovereign`

## Notes

- The upstream `scripts/install.sh` still installs official Coolify from
  `cdn.coollabs.io`; do not use it for the fork.
- The installer sets `AUTOUPDATE=false` so upstream auto-update does not replace
  the fork image.
- For production, put Cloudflare or another TLS proxy in front of the VPS and
  point your Coolify domain to the server.
