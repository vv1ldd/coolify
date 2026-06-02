# Control Plane Sync and Edge Agents

## Context

Each Coolify installed on a VDS/VPS can act as a local sentinel for its part of the network. A single instance sees local server reachability, usable proxy capacity, DNS steering readiness, edge policy versions, and regional readiness hints. Multiple instances need a narrow way to exchange those observations so a surviving control plane can make better DNS failover decisions later.

## Decision

Introduce a minimal Control Plane Sync foundation:

- `ControlPlanePeer` registers another Coolify control plane or edge agent for one team. It stores endpoint metadata, region/role/capabilities, an encrypted shared secret for HMAC verification, a hidden secret hash reference, `last_seen_at`, and status.
- `ControlPlaneSnapshot` stores signed heartbeat evidence from a peer. It contains server/node health summaries, DNS steering readiness, EdgePolicy versions, Regional Readiness summaries, and free-form metadata.
- `POST /api/v1/control-plane/sync/snapshot` and `POST /api/v1/control-plane/heartbeat` accept signed peer snapshots. The request must include `X-Control-Plane-Peer`, `X-Control-Plane-Timestamp`, and `X-Control-Plane-Signature`.
- `GET /api/v1/control-plane/peers` lists peer status for the current API token team without exposing secret material.
- `control-plane:heartbeat` builds a local snapshot and is dry-run by default. `--send` is explicit and requires a configured endpoint, shared secret, and `metadata.local_peer_uuid`.
- `control-plane:status` displays peer freshness and snapshot counts.

## Boundaries

- Control Plane Sync observes and synchronizes state only.
- DNS Steering owns desired DNS topology and applies DNS changes after explicit operator action.
- EdgePolicy owns L7 protection configuration.
- Regional Readiness owns readiness assessment.
- Provider Control owns provider-side actions such as VM controls.

The sync layer must not perform cross-node destructive actions. It must not call DNS providers, mutate EdgePolicy, mutate Provider Control resources, or rotate traffic automatically.

## Consistency Model

Peer observations are eventually consistent evidence. A snapshot is a point-in-time claim signed by a configured peer secret, not a quorum decision and not an authority projection. Stale peers are computed from `last_seen_at`; consumers should treat stale observations as lower-confidence or ignore them.

DNS Steering may consume a normalized health map derived from recent snapshots as a future health source. Planning can use those observations to produce a dry-run failover plan, but applying DNS records remains a separate explicit DNS Steering/DNS Zones operation.

## Prerequisites for Failover

- At least two Coolify instances registered as peers for the same team.
- Shared secrets exchanged out of band and stored only as encrypted secret material plus hidden hash reference.
- Candidate DNS steering policies that include server UUIDs or IPs matching observed nodes.
- A DNS zone/provider token configured in DNS Zones.
- An operator or future safe automation path that explicitly applies a DNS Steering plan.

## Non-goals

- No automatic DNS failover loop.
- No provider shutdown/reboot/delete actions.
- No leader election or consensus protocol.
- No UI beyond API and artisan visibility.
- No secrets exposed through API responses.
