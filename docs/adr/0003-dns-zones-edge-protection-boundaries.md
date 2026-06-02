# DNS Zones and Edge Protection Boundaries

## Context

Coolify should be able to manage DNS records through provider APIs, starting with Cloudflare, without moving L7 request policy into application or domain binding ownership.

## Bounded contexts

- DNS Zones own DNS provider configuration, encrypted provider credentials, remote zone identifiers, and managed DNS records.
- DNS Steering owns planned A/AAAA changes for domains that Coolify proxies itself without Cloudflare proxying. It consumes node/server health and candidate edge capacity, then asks DNS Zones to apply provider records only after an explicit operator action.
- Edge Protection owns L7 request policy such as Traefik rate limits, in-flight limits, suspicious user-agent blocking, probe path blocking, and security headers.
- Servers own physical edge capacity and readiness signals such as reachability, usability, build-server exclusion, and force-disabled state.
- Applications own deployment and runtime behavior.
- Domain Bindings compose application domains with DNS records, DNS steering preview, node candidates, and edge policy, but do not own provider credentials or records.
- Regional Readiness assesses deployment suitability only.

## MVP behavior

The MVP exposes backend API endpoints under `/api/v1/dns-zones`:

- Create/list/show/update/delete DNS zones for the current API token team.
- List Cloudflare zones and records through the stored encrypted token.
- Upsert managed `A`, `CNAME`, and `TXT` records to Cloudflare and store a local projection in `dns_records`.
- Delete a managed record from Cloudflare when it has a provider record id, then remove the local projection.

`dns_records.application_id` is nullable and exists only as a composition reference. It lets an app/domain binding workflow ask DNS Zones to manage a record for an app domain, while DNS Zones still own provider state and Applications remain deployment/runtime only.

## Dynamic DNS steering

Dynamic DNS steering is part of the DNS control plane, not Edge Protection. A `DnsSteeringPolicy` is tied to a team/domain and optionally to a DNS zone, application, or resource. It tracks candidate server UUIDs/IPs, a strategy (`static`, `active_passive`, `weighted_round_robin`, `health_based`), an explicit `enabled` flag, computed desired A/AAAA records, `last_applied_at`, and metadata such as TTL.

The steering service produces a dry-run plan by comparing desired A/AAAA records from healthy candidates against local managed `DnsRecord` rows. Planning never calls Cloudflare and never mutates provider state. Applying is a separate explicit operation that calls `DnsZoneService`; disabled policies cannot be applied. There is no background auto-rotation in this foundation.

The MVP intentionally plans at most one A and one AAAA target per domain because the current managed DNS projection is unique by zone, type, and name. Multi-record weighted DNS, health probe ownership, scheduler-driven rotation, and Regional Readiness integration are future work. DNS steering may consume health/readiness outputs, but it does not own EdgePolicy decisions or Regional Readiness decisions.

## RU domains

For `.ru`, `.рф`, and `.xn--p1ai` hostnames, the DNS service forces `proxied=false` before calling Cloudflare. Cloudflare is used as DNS only for these records, while Coolify and Traefik continue to provide L7 edge protection through the existing `coolify-edge-*` label generation in `bootstrap/helpers/docker.php`.

## Non-goals

- No full UI is introduced in this MVP.
- No real Cloudflare tokens are committed or used in tests.
- No changes are made to existing Traefik edge label generation.
- No Regional Readiness or app deployment policy is added here.
- No destructive DNS auto-rotation is enabled by default.
