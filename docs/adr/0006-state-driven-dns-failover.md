# ADR 0006: State-driven DNS Failover Across Coolify Control Planes

## Status

Accepted as a backend foundation.

## Context

DNS Steering owns the desired DNS topology for a domain. Regional readiness, server reachability, and provider control signals are inputs to that topology; they do not mutate DNS records directly. This keeps failover decisions reproducible from database state and lets another Coolify control plane evaluate the same policy if the first control plane is down.

## Decision

Coolify evaluates `DnsSteeringPolicy` rows into dry-run plans. For `active_passive` and `health_based` strategies the plan chooses the first healthy candidate by priority. Candidate health is derived from `Server` settings (`is_reachable`, `is_usable`, `force_disabled`, build-server status) and can be overridden in policy metadata for imported state or tests.

Applying a plan is explicit:

- `dns:steering:evaluate` is dry-run by default.
- `dns:steering:evaluate --apply` applies only policies with `enabled=true`.
- `DnsZoneService` is the only component that writes provider DNS records.
- Plans include clear reasons such as `primary_down`, `candidate_healthy`, `no_healthy_candidates`, `current_record_mismatch`, and `no_change`.

## Cross-control-plane operation

A second Coolify instance can update DNS when the first one is down if it has:

- A replicated or imported `DnsSteeringPolicy` row for the domain.
- The corresponding `DnsZone` row with a valid provider zone id and DNS token.
- Candidate nodes with stable server UUIDs/IPs, or policy metadata health overrides from an external readiness source.
- Local `DnsRecord` state that is recently synced enough to compare planned and current A/AAAA records.

The second control plane runs `php artisan dns:steering:evaluate --apply` and applies the enabled safe plan through `DnsZoneService`. No call back to the failed Coolify instance is required.

## Guardrails

Automatic mutation is disabled by default. Operators must opt into applying by enabling the policy and passing `--apply`. Plans with no healthy candidates are not applied. Existing unmanaged records are treated as conflicts and left untouched.

Low TTLs are required for useful failover. Policies should use a TTL suitable for the provider and business tolerance, commonly 60 seconds for this foundation. DNS cache behavior means failover is not instantaneous even when the provider update succeeds.

## Consequences

This is a minimal backend foundation. It does not add UI controls, background scheduling, health probing ownership, multi-record weighted DNS, quorum between control planes, or automatic replication of DNS tokens/policy state.
