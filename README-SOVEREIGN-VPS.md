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

## Install On A Fresh VPS

Run as `root` or with `sudo`.

```bash
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | bash
```

Optional pre-created root user:

```bash
export ROOT_USERNAME=admin
export ROOT_USER_EMAIL=admin@example.com
export ROOT_USER_PASSWORD='change-this-password'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | bash
```

Optional custom ports/image:

```bash
export APP_PORT=8000
export SOKETI_PORT=6001
export COOLIFY_IMAGE=ghcr.io/vv1ldd/coolify:sovereign
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | bash
```

Optional SL1 Connect settings:

```bash
export SL1_CONNECT_ISSUER=https://simplel1.online
export SL1_CONNECT_CLIENT_ID=coolify.sovereign
export SL1_CONNECT_CLIENT_NAME='Sovereign Coolify'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | bash
```

After installation, open:

```text
http://SERVER_IP:8000
```

## Upgrade The Fork

After pushing a new `sovereign` image:

```bash
sudo bash /data/coolify/source/upgrade-sovereign.sh
```

Update rail:

1. Commit code to branch `sovereign`.
2. Push `origin sovereign`.
3. Wait for `Sovereign Build` to publish `ghcr.io/vv1ldd/coolify:sovereign`.
4. Run `sudo bash /data/coolify/source/upgrade-sovereign.sh` on the VPS.
5. Verify `/api/health` and one SL1 login round-trip.

## Files Added For The Fork

- `scripts/install-sovereign.sh` - fresh VPS installer
- `scripts/upgrade-sovereign.sh` - pulls the fork image and restarts compose
- `docker-compose.sovereign.prod.yml` - overrides the app image to the fork image
- `.github/workflows/sovereign-build.yml` - publishes `ghcr.io/vv1ldd/coolify:sovereign`

## Notes

- The upstream `scripts/install.sh` still installs official Coolify from
  `cdn.coollabs.io`; do not use it for the fork.
- The installer sets `AUTOUPDATE=false` so upstream auto-update does not replace
  the fork image.
- For production, put Cloudflare or another TLS proxy in front of the VPS and
  point your Coolify domain to the server.
