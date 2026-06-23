# Sovereign Coolify VPS Install

This fork keeps the upstream Coolify installer untouched and adds a separate
Sovereign install path for VPS testing.

## What This Installs

- Coolify application image: `ghcr.io/vv1ldd/coolify:sovereign`
- Simple L1 node image: `ghcr.io/vv1ldd/simple-l1:latest`
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

### Git clone (recommended — includes disk encryption scripts)

```bash
export SOVEREIGN_RUNTIME_CONVERGE_OWNER=true
export SOVEREIGN_ASSUME_YES=true
export SOVEREIGN_HOST_DOMAIN=ops.meanly.one
export SIMPLE_L1_DOMAIN=identity.meanly.one
export SIMPLE_L1_ISSUER_URL=https://identity.meanly.one/sl1
export SL1_CONNECT_ISSUER=https://identity.meanly.one
export SL1_CONNECT_CLIENT_ID=meanly.ops
export SIMPLE_L1_PUBLIC_IP='YOUR_VPS_IP'

git clone --depth 1 -b sovereign https://github.com/vv1ldd/coolify.git /tmp/coolify-sovereign
bash /tmp/coolify-sovereign/scripts/install-sovereign.sh
```

Or one curl that clones then installs:

```bash
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/bootstrap-sovereign-from-git.sh \
  -o /tmp/bootstrap-sovereign-from-git.sh
bash /tmp/bootstrap-sovereign-from-git.sh
```

Optional LUKS before converge (`SOVEREIGN_DISK_ENCRYPT=auto`) — see `scripts/sovereign-disk/README.md`.

### Raw curl (runtime only, no disk scripts on disk)

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

Optional canonical host domain (panel + identity split):

```bash
export SOVEREIGN_HOST_DOMAIN=ops.meanly.one
export SOVEREIGN_IDENTITY_DOMAIN=identity.meanly.one
export SOVEREIGN_APP_SCHEME=https
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

When canonical domains are provided, the installer syncs:

- `APP_URL` / panel → `SOVEREIGN_HOST_DOMAIN`
- `SIMPLE_L1_DOMAIN` / `SL1_CONNECT_ISSUER` → `SOVEREIGN_IDENTITY_DOMAIN` (or panel domain when unset)

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
export SIMPLE_L1_ISSUER_URL=https://simplel1.online/sl1
export SL1_CONNECT_CLIENT_ID=coolify.sovereign
export SL1_CONNECT_CLIENT_NAME='Sovereign Coolify'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

## Simple L1 Failover

Sovereign Coolify starts a dedicated `simple-l1` container by default through
`docker-compose.sovereign.prod.yml`. Coolify depends on this service being
healthy, so a Sovereign node boot brings up both the control panel and the local
Simple L1 runtime. The embedded Laravel runtime remains a fallback/projection
layer, not the primary container runtime.

The `simple-l1` service:

- uses `SIMPLE_L1_IMAGE`, defaulting to `ghcr.io/vv1ldd/simple-l1:latest`
- mounts `simple-l1-data` as a local cache/replay accelerator, not identity authority
- enables the Identity Capsule protocol by default:
  `IdentityCapsule` proves provenance, `StateProof` proves freshness, and
  WebAuthn proves passkey possession
- health-checks `http://127.0.0.1:3000/healthcheck`
- exposes Traefik routers for `SIMPLE_L1_DOMAIN`, defaulting to `simplel1.online`
- exposes an HTTP-only `/healthcheck` route before HTTPS redirect so peer nodes
  can probe `http://NODE_IP/healthcheck` with `Host: simplel1.online`

The default resolver contract is:

```text
SIMPLE_L1_STORAGE_ROLE=cache
SIMPLE_L1_IDENTITY_CAPSULES_ENABLED=true
SIMPLE_L1_EVIDENCE_RESOLVERS=local-cache,client-capsule,peer,signed-export
SIMPLE_L1_STATE_RESOLVERS=local-cache,peer,anchor,quorum,signed-export
SIMPLE_L1_DEFAULT_ASSURANCE_LEVEL=AL1
```

This means a new node can rebuild a local identity projection from evidence
instead of treating `/data/simple-l1/ledger_db.json` as the only source of truth.
If no fresh `StateProof` is available, the proof is intentionally downgraded to
bounded/offline assurance instead of pretending to be fully current.

### Updating Bundled Simple L1

The bundled `simple-l1` runtime is updated through its container image. The
`simple-l1` repository publishes:

- `ghcr.io/vv1ldd/simple-l1:latest`
- `ghcr.io/vv1ldd/simple-l1:<commit-sha>`

Runtime image version and identity protocol version are intentionally separate:

```text
SIMPLE_L1_IMAGE=ghcr.io/vv1ldd/simple-l1:<commit-sha>
SIMPLE_L1_IDENTITY_PROTOCOL_VERSION=capsule-v0
```

The image tag identifies the build. `SIMPLE_L1_IDENTITY_PROTOCOL_VERSION`
identifies compatibility for `IdentityCapsule`, `ControllerBinding`, and
`StateProof` schemas. A runtime refresh is safe only when the container reports
the expected protocol version after restart.

On a Sovereign Coolify node, refresh the whole bundle:

```bash
SOVEREIGN_RUNTIME_CONVERGE_OWNER=true \
SOVEREIGN_INSTALL_MODE=refresh \
SOVEREIGN_ASSUME_YES=true \
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

That command pulls `COOLIFY_IMAGE`, `SOVEREIGN_REALTIME_IMAGE`, and
`SIMPLE_L1_IMAGE`, restarts the composed runtime, and verifies the Simple L1
identity runtime through `/api/sl1e/connect/status`. To pin a specific Simple L1
build, export the SHA-tagged image before running the refresh:

```bash
export SIMPLE_L1_IMAGE=ghcr.io/vv1ldd/simple-l1:<commit-sha>
SOVEREIGN_RUNTIME_CONVERGE_OWNER=true \
SOVEREIGN_INSTALL_MODE=refresh \
SOVEREIGN_ASSUME_YES=true \
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

Each node can serve the public Simple L1 domain and keep a Cloudflare DNS
steering policy ready for the `simplel1.online` record.

Configure every failover node with the same domain and Cloudflare zone settings,
but with its own public IP:

```bash
export SIMPLE_L1_DOMAIN=simplel1.online
export SIMPLE_L1_ISSUER_URL=https://simplel1.online/sl1
export SIMPLE_L1_IMAGE=ghcr.io/vv1ldd/simple-l1:latest
export SIMPLE_L1_NODE_NAME=br-primary
export SIMPLE_L1_CLOUDFLARE_API_TOKEN='cloudflare-token-with-zone-read-dns-edit'
export SIMPLE_L1_PUBLIC_IP='203.0.113.10'
export SIMPLE_L1_FAILOVER_NODES='primary=203.0.113.10,backup=203.0.113.11'
export SIMPLE_L1_DNS_STEERING_ENABLED=true
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

When the installer has access to `/dev/tty` and no token is provided through env
or an existing `.env`, it asks whether to configure the Cloudflare token for
Simple L1 DNS failover during both fresh installs and refresh/upgrade runs. This
Cloudflare prompt still appears when `SOVEREIGN_ASSUME_YES=true`; that flag only
skips the install-mode menu. The token is read with a silent prompt and written
only to `/data/coolify/source/.env`; it is never baked into the image or
repository.
When a token is configured and `SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE` was not
set explicitly, the installer enables scheduled
`sovereign:simple-l1-failover --apply` so a healthy peer can move the Cloudflare
record after the active node fails.
The dedicated Simple L1 failover loop is `sovereign:simple-l1-failover`; it only
promotes a new IP when the current Cloudflare A target is unhealthy. It does not
automatically fail back while the current target is still healthy.

Every run persists the observation/election trail:

- `simple_l1_node_observations` stores per-node health evidence
- `simple_l1_failover_decisions` stores the recommendation, reason, evidence
  hash, and applied DNS result when a promotion happens

This makes the failover answer auditable later: not just "where does DNS point",
but "why did we point it there".

The layer boundary is captured in
`docs/adr/0008-observation-does-not-imply-authority.md`: observations may
influence recommendations, recommendations may influence decisions, and decisions
may authorize control actions. Observations alone never authorize DNS writes.

For non-interactive installs, pass the token explicitly:

```bash
export SIMPLE_L1_CLOUDFLARE_API_TOKEN='cloudflare-token-with-zone-read-dns-edit'
export SIMPLE_L1_PUBLIC_IP='203.0.113.10'
export SIMPLE_L1_FAILOVER_NODES='primary=203.0.113.10,backup=203.0.113.11'
curl -fsSL https://raw.githubusercontent.com/vv1ldd/coolify/sovereign/scripts/install-sovereign.sh | sudo -E bash
```

The bootstrap command can also be run manually:

```bash
docker exec coolify php artisan sovereign:simple-l1-bootstrap --enable
docker exec coolify php artisan dns:steering:evaluate --apply --json
```

For continuous autonomous failover, explicitly enable the scheduler:

```bash
export SOVEREIGN_SIMPLE_L1_FAILOVER_SCHEDULE=apply
```

Keep these flags off until the Cloudflare policy has been verified with:

```bash
docker exec coolify php artisan dns:steering:evaluate --json
docker exec coolify php artisan sovereign:simple-l1-failover --json
```

The bootstrap stores each candidate as a per-IP probe, for example:

```text
primary -> http://203.0.113.10/healthcheck with Host: simplel1.online
backup  -> http://203.0.113.11/healthcheck with Host: simplel1.online
```

This is important because checking `https://simplel1.online` only tests the
currently active DNS target. Per-IP probes let a backup Coolify node detect that
the active server is down and safely promote another healthy IP.

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
- pulls the configured Sovereign, realtime, and Simple L1 images
- restarts the Coolify runtime
- runs database migrations
- rebuilds Laravel caches
- bootstraps the Simple L1 DNS failover policy when Cloudflare/IP settings are available
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
- `docker-compose.sovereign.prod.yml` - overrides the app image and adds the
  default `simple-l1` runtime dependency
- `.github/workflows/sovereign-build.yml` - publishes `ghcr.io/vv1ldd/coolify:sovereign`

## Notes

- The upstream `scripts/install.sh` still installs official Coolify from
  `cdn.coollabs.io`; do not use it for the fork.
- The installer sets `AUTOUPDATE=false` so upstream auto-update does not replace
  the fork image.
- For production, put Cloudflare or another TLS proxy in front of the VPS and
  point your Coolify domain to the server.
