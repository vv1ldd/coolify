# ADR-0002: Sovereign Compliance Map

Status: Proposed

## Purpose

This document tracks how the Sovereign Coolify fork complies with
ADR-0001: Sovereign Infra Kernel.

It is a living architectural state machine, not a static design note. Each
subsystem is classified by its current relationship to the kernel:

- `aligned`: follows the authority-lineage-first mutation model.
- `transitional`: directionally correct, but missing a required boundary.
- `violation`: contains an implicit authority or direct execution bypass that
  must be refactored before extension.
- `presentation`: UI-only layer; must not become an authority source.

Every PR that adds or changes an infrastructure mutation path must update this
map or explain why no compliance state changed.

## Compliance Matrix

| Subsystem | Status | Violation Type | Required Refactor | Target PR |
| --- | --- | --- | --- | --- |
| SL1-only authentication | aligned | none | Keep Coolify users as projections of SL1 identities; do not reintroduce password authority. | `sl1-only-auth` |
| Sovereign installer and update rail | aligned | none | Keep fork updates on `ghcr.io/vv1ldd/coolify:sovereign`; keep upstream auto-update disabled. | `sovereign-install-update` |
| InfraLedger | aligned | none | Preserve append-only semantics; normal and emergency exec lineage must remain sealed with authorization context. | `ledger-migration-cleanup` |
| PolicyEngine `container.exec.command` | aligned | none | Keep as pure `InfraIntent + Context + RiskSignal -> PolicyDecision`; no persistence, artifact creation, or execution side effects. | `policy-engine-pure-evaluator` |
| Legacy PolicyEngine staged flows | transitional | evaluator/executor boundary mixed | Extract remaining stage/sign/release paths into the same pure-decision pipeline before extending them. | `policy-engine-pure-evaluator` |
| Authorization artifacts | aligned | none for `container.exec.command` | `infra_authorizations` require a `PolicyDecision` producer and remain consumable, replay-safe authority objects. | `authorization-artifacts-v1` |
| Identity sovereignty tails | aligned | none | Email change, email verification, magic-link login, password reset, password registration, forced password reset, and Fortify 2FA are constitutionally disabled under SL1-only identity. | `identity-sovereignty-tail-collapse` |
| SL1 notification transport | aligned | none | `sl1.notification.v1` envelopes are non-authoritative discovery pointers; they cannot grant, transfer, consume, or mutate authority. | `sl1-notification-v1` |
| AgentContainerService logs | transitional | token-driven read capability | Model as `container.logs.read` capability; allow low-risk read path only through explicit policy decision. | `agent-exec-capability` |
| AgentContainerService exec | aligned | none | Normal execution follows `PolicyDecision -> AuthorizationArtifact -> consume -> execute -> InfraLedger`; root token is restricted to ledger-recorded emergency violation mode. | `agent-exec-capability` |
| Team member invite | aligned | none for issuance | `team.member.invite` creates only a non-consumable `TeamInvitationArtifact`; email is delivery metadata and legacy acceptance is blocked for `team.invitation.v1`. | `team-invitation-artifacts-v1` |
| Team member join | transitional | consumption not implemented | Implement `SL1 IdentityProof + TeamInvitationArtifact -> PolicyDecision -> consume -> membership mutation -> InfraLedger`; until then v1 artifacts cannot create membership. | `team-member-join-v1` |
| Legacy team invitations | violation | email/password authority bridge | Remove password bootstrap, email-as-principal, mutable role authority, and direct `team_user` attach semantics for legacy invitations. | `team-member-join-v1` |
| SecurityObservation | aligned | none if signal-only | Keep as `RiskSignal` input only; never auto-ban, auto-allow, or mutate infrastructure directly. | `adaptive-risk-signals` |
| Traefik traffic filters | transitional | enforcement before policy lineage | Keep edge filters as protective controls and observations; route high-risk signals into policy escalation. | `adaptive-risk-signals` |
| UI and rebrand layer | presentation | authority illusion risk | UI may create intents and display lineage, but must not imply authority or call execution adapters directly. | `presentation-after-kernel` |

## Violation Taxonomy

### Direct Execution Bypass

A controller, service, job, token, or UI path mutates infrastructure without a
valid `AuthorizationArtifact`.

Examples:

- root token executes shell directly;
- admin button deletes a server directly;
- job rotates a secret without policy lineage.

### Shadow Authority Layer

A subsystem starts deciding authority outside the kernel.

Examples:

- risk engine silently denies or allows mutation;
- policy engine both evaluates and executes;
- UI assumes admin role implies universal authority.

### Lineage Break

Mutation occurs, but the ledger cannot reconstruct the consumed authority.

Examples:

- best-effort logging after execution;
- migration rewrites historical semantics;
- ledger entry has no authorization reference.

### Emergency Normalization

Emergency access exists but becomes reusable, default, silent, or untracked.

Emergency mode must remain a ledger-recorded constitutional violation path, not
a production authorization shortcut.

### Cross-Semantic Authority Bridge

A legacy path consumes or interprets a new bounded artifact using old authority
semantics.

Examples:

- legacy team invitation acceptance consumes `team.invitation.v1`;
- email possession is treated as identity proof;
- a copied invitation URL creates membership without SL1 proof and artifact
  consumption.

### Delivery-Controlled Identity Mutation

An email, password, OTP, recovery, or magic-link path mutates identity authority.

Examples:

- `pending_email + email_change_code` changes the user principal;
- password reset token possession changes authentication authority;
- magic-link delivery possession authenticates or bootstraps a user;
- Fortify 2FA challenge/recovery coexists as a second identity root beside SL1.

### Authoritative Transport Collapse

A notification, email, copied link, or inbox message is treated as authority.

Examples:

- email delivery creates team membership;
- notification possession consumes `team.invitation.v1`;
- read/dismiss status changes artifact validity or role scope;
- transport status creates grants, authorizations, or team membership.

## State Transition Rules

Allowed compliance transitions:

```text
missing -> transitional -> aligned
violation -> transitional -> aligned
presentation -> aligned
```

Forbidden transitions:

```text
violation -> aligned without removing the bypass
transitional -> aligned without a test or runtime gate
aligned -> transitional without updating this map
aligned -> violation without an explicit emergency rationale
```

## First Refactor Target

The highest-priority violation was `AgentContainerService::exec()`.

Previous shape:

```text
root API token -> shell command execution
```

Current target shape:

```text
SL1 Identity
  -> InfraIntent(container.exec.command)
  -> PolicyDecision
  -> AuthorizationArtifact(scope, target, ttl, replay key)
  -> AuthorizationConsumption
  -> ExecutionAdapter
  -> InfraLedgerEntry
```

The first refactor removes the production direct execution path and requires a
consumable authorization artifact for normal execution. Root-token execution may
remain only as emergency mode, and only if it records an explicit constitutional
violation event in the infra ledger.

The producer-side refactor closes the next boundary for `container.exec.command`:
the `PolicyEngine` evaluates only, `PolicyDecision` is persisted as the immutable
decision artifact, and `InfraAuthorizationService` only translates an allowed
decision into a consumable artifact.

The finality refactor seals successful non-emergency execution into `InfraLedger`
with the consumed authorization id, policy decision id, command hash, target, and
output hash. The kernel path for `container.exec.command` is therefore aligned:
no hidden decision point, no hidden authorization factory, no unrecorded
execution authority.

## Team Invitation Issuance Slice

`team.member.invite` is the first authority issuance proof slice.

Theorem property:

```text
A team invitation may create only a bounded, non-consumable authority
opportunity, never membership authority.
```

Phase 1 shape:

```text
TeamInviteIntent
  -> PolicyDecision(team.member.invite)
  -> TeamInvitationArtifact(team.invitation.v1)
  -> InfraLedgerEntry(team.member.invite)
  -> NotificationEnvelope(sl1.notification.v1)
  -> DeliveryChannel(SL1 inbox or discovery fallback)
```

Forbidden Phase 1 bridges:

- invite issuance must not create `User`;
- invite issuance must not create password bootstrap links;
- delivery email is metadata, not principal identity;
- role scope comes from immutable artifact scope, not mutable form or display
  fields;
- legacy `/invitations/{uuid}` must not consume `team.invitation.v1`;
- copied invitation links alone must not attach membership.
- notification possession must not consume an artifact or mutate membership.

Consumption is intentionally absent in Phase 1. `team.member.join` remains a
separate transitional slice.

## SL1 Notification Transport Slice

`sl1.notification.v1` is a protocol transport boundary for discovery, not
authority.

Theorem property:

```text
A notification may disclose the existence of an authority artifact,
but may never grant, transfer, consume, or mutate authority.
```

Coolify integration shape:

```text
TeamInvitationArtifact
  -> Sl1NotificationEnvelope(sl1.notification.v1)
  -> DiscoveryChannel(email/manual today, SL1 inbox next)
```

Runtime invariants:

- `authority_effect` is always `none`;
- `non_authoritative` is always `true`;
- `consumes_artifact` and `mutates_authority` are always `false`;
- `capabilities_granted` is always empty;
- read/dismiss status changes metadata only;
- notification events are recorded in `InfraLedger` without creating
  membership.

## Identity Sovereignty Tail Collapse

This cleanup is a transitional theorem slice, not a new mutation domain.

Theorem property:

```text
No email, password, OTP, recovery, or delivery-controlled flow may mutate
identity authority within SL1 runtime semantics.
```

Current aligned shape:

```text
SL1 IdentityProof
  -> Coolify User projection
  -> contact/display metadata
```

Disabled bridges:

- `Change Email` UI and model-level `requestEmailChange/confirmEmailChange`;
- signed email verification links mutating `email_verified_at`;
- password registration, password reset, and password update features;
- legacy magic-link bootstrap through `/auth/link`;
- forced password reset detours from legacy invitation bootstrap;
- Fortify OTP/two-factor challenge and recovery surfaces.

Email remains projection/contact metadata. It is not a principal, recovery root,
or authority source.

## Review Gate

For every mutation path, reviewers must ask:

```text
Can this mutation reconstruct its consumed bounded authority lineage?
```

If the answer does not name the proof, bounded artifact, policy decision,
consumption event, exact mutation, and ledger finality entry, the PR is not
compliant with ADR-0001.

## Appendix A: Mutation Surface Protocol v1.0

Before any mutation-domain implementation, all potential state mutation surfaces
must be enumerated, classified, and neutralized, constrained, or explicitly moved
behind bounded authority consumption.

Core rule:

```text
Unclassified mutation surface == sovereignty risk.
No implementation may begin until mutation surface audit is complete.
```

Surface classes:

```text
DIRECT GRAPH MUTATION
```

Directly modifies graph state such as membership, role, permissions, relations,
or other authority topology. It bypasses the
`artifact -> policy -> consumption` chain.

Action: remove, disable, or move behind bounded consumption.

```text
AUTHORITY-ADJACENT MUTATION
```

Can influence or indirectly affect graph authority, role scope, policy scope, or
future mutation eligibility.

Action: explicitly prove non-authoritative through negative tests.

```text
LEGACY SOFT BRIDGE
```

Legacy UI, controller, acceptance, login, session, or link semantics that imply
authority through possession or runtime continuity.

Action: demolish or make inert with a hard denial, redirect, or no-op.

```text
TRANSPORT SURFACE
```

Notification, email, link, session routing, or other discovery/delivery
mechanism.

Invariant: must not mutate state, consume artifacts, or grant authority.

```text
FUTURE CONFLICT ZONE
```

Belongs to a later bounded consumption domain. It may exist structurally but
must be inert in the current slice.

Action: explicitly mark as out of scope and non-mutating.

Mandatory preflight steps:

```text
1. enumerate mutation surfaces;
2. classify every surface;
3. define negative invariants;
4. block legacy mutation paths;
5. only then implement bounded authority paths.
```

LLM/agent rule:

```text
Never assume authority from context.
Always require mutation -> reconstructable consumed bounded authority lineage.
```

PR invariant:

```text
This PR does not implement <domain mutation>.
It proves that <target state> cannot be mutated through legacy or
possession-based surfaces.
```

Exit criteria:

- all mutation surfaces are enumerated;
- all surfaces are classified;
- direct mutations are eliminated, blocked, or moved behind consumption;
- legacy bridges are disabled;
- transport surfaces are proven inert;
- future conflict zones are explicitly non-mutating;
- negative tests enforce forbidden transitions.

## Appendix B: Constitutional Preflight System v1.0

The preflight system is a visibility layer over mutation surfaces.

Phase 1 is advisory only:

```text
Preflight must never block.
Preflight must always surface hidden mutation risk.
```

It does not:

- enforce correctness;
- validate legitimacy;
- block execution;
- replace tests or review.

It does:

- enumerate mutation-surface risk patterns;
- surface hidden authority risk in pull requests;
- require a standardized mutation surface audit structure;
- make mutation surfaces visible before they become executable abstractions.

All mutation-domain PRs must include a `Mutation Surface Audit` section before
implementation is considered constitutionally valid.

Future phases may hard-fail missing audit sections, add semantic scanning, or
require machine-readable classification. Phase 1 remains non-blocking by design.
