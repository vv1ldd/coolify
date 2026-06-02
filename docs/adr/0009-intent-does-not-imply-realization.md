# ADR 0009: Intent Does Not Imply Realization

## Status

Accepted for Edge Control Plane.

## Context

The Edge Control Plane manages domain, DNS, routing, and edge policy intent. It
must not treat that intent as provider-specific infrastructure state. A desired
record is not a Cloudflare record. A traffic policy is not a Traefik route.

Without a boundary between intent and execution, provider details leak into the
domain model and make future adapters expensive: Route53, Hetzner DNS, PowerDNS,
authoritative nameservers, Traefik, Envoy, Nginx, and a future edge agent would
all compete to become the model.

## Decision

Edge Control Plane has four layers:

1. Intent: desired domain, zone, routing, and edge policy state.
2. Projection: adapter-specific desired state generated from intent.
3. Execution: adapter control actions that attempt to apply a projection.
4. Observation: real-world state observed after execution.

This means:

- Intent does not imply realization.
- Projection does not imply execution.
- Observed state does not imply applied state.

Examples:

- `DnsRecord` does not imply `CloudflareRecord`.
- `EdgePolicy` does not imply `TraefikRoute`.
- `EdgeProjection(status=generated)` does not imply the adapter applied it.
- `EdgeControlAction(status=succeeded)` does not imply traffic or resolver
  behavior matches the desired state.

## Invariants

- Intent stores desired state.
- Projection stores adapter-specific desired state.
- Adapter execution stores apply attempts and results.
- Observation stores real-world state.
- Adapters consume projections, not raw intent.
- Adapter execution must reference the projection hash it applied.
- Projection versions are append-only.
- Control actions are append-only.
- Capabilities constrain projection scheduling.

## Drift Model

Each projection chain records hashes:

- `intent_hash`: desired-state fingerprint.
- `projection_hash`: adapter-specific desired-state fingerprint.
- `applied_projection_hash`: projection fingerprint confirmed by execution.
- `observed_state_hash`: observed-world fingerprint after execution.

These support three drift classes:

- Projection drift: projection was generated from stale or unexpected intent.
- Execution drift: adapter applied a different projection than generated.
- Observation drift: the real world does not match the applied projection.

## Consequences

The system can explain:

- What did the user want?
- What adapter-specific config was generated?
- Which adapter applied it?
- What did the adapter report?
- What did the world actually observe?

Cloudflare and Traefik remain important first adapters, but neither becomes the
domain model.
