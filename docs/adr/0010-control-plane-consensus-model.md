# ADR 0010: Control Plane Consensus Model

## Status

Accepted for Edge Control Plane readiness and future adapter reconciliation.

## Context

The Edge Control Plane separates intent, projection, execution, and observation.
That separation prevents provider-specific state from becoming the domain model,
but it creates a new question: what happens when these layers disagree?

Examples:

- Cloudflare returns success, but resolvers still observe the old record.
- Traefik accepts config, but traffic fails TLS or host routing checks.
- A projection is schedulable according to stale node capabilities.
- Cloudflare and a future authoritative DNS adapter produce conflicting effects.

## Decision

The system must treat truth as reconciled, not assumed.

This adds four rules:

- Adapter success is not observed truth.
- Node capability is observed state, not a permanent property.
- Intent drift is distinct from execution drift.
- Multi-adapter conflicts require explicit resolution policy.

## Consensus Boundaries

Observation trust has three minimum dimensions:

- confidence score;
- observation window;
- quorum status.

Capability scheduling must snapshot:

- required capabilities;
- matching nodes;
- capability hash;
- capability version.

Projection state must expose:

- whether current intent differs from the applied projection;
- which conflict policy governs multiple adapters;
- whether observation confirms the applied state.

## Drift Classes

- Intent drift: intent or projection changed after the last applied projection.
- Projection drift: generated projection does not match expected intent input.
- Execution drift: adapter applied a hash different from the projection hash.
- Observation drift: observed state differs from the applied projection hash.

## Conflict Policy

V1 does not auto-merge conflicting adapter effects. It records policy intent and
history only.

Initial policy:

- append-only history;
- no implicit rollback;
- no hidden winner;
- conflicts must be surfaced to the operator.

Future policies may include precedence, quorum approval, emergency override, or
explicit rollback commands.
