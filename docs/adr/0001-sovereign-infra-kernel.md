# ADR-0001: Sovereign Infra Kernel

Status: Proposed

## Context

The Sovereign Coolify fork is moving from a self-hosting dashboard toward an
authority-aware infrastructure control plane. Infrastructure operations such as
server deletion, shell execution, secret rotation, deploys, and proxy restarts
are high-authority mutations. They must not be treated as direct consequences of
UI clicks, API tokens, agent prompts, or risk-engine scores.

The primary failure mode is sovereignty drift: a component that was introduced
as a convenience path slowly becomes implicit full authority. Common examples
are root tokens, admin buttons, automation jobs, AI agents, and emergency
fallbacks.

This ADR defines what the system refuses to become.

## Decision

Sovereign Coolify uses an authority-lineage-first mutation kernel:

```text
SL1 Identity
  -> InfraIntent
  -> PolicyEvaluation
  -> AuthorizationArtifact
  -> ExecutionAdapter
  -> InfraLedgerEntry
```

Minimum invariant:

```text
No infra mutation without AuthorizationArtifact.
No AuthorizationArtifact without PolicyEvaluation.
No PolicyEvaluation without Identity-bound InfraIntent.
No InfraLedgerEntry without consumed authorization lineage.
```

Absence of a valid bounded authority lineage is denial by construction.

## Definitions

- `SL1 Identity`: root origin of human or controller authority. A local Coolify
  user is only an application projection of an SL1 entity.
- `InfraIntent`: a bounded statement of intended infrastructure mutation.
- `PolicyEvaluation`: explicit evaluation of the intent under current policy,
  risk, role, team, resource, and temporal constraints.
- `AuthorizationArtifact`: replay-constrained permission to execute exactly the
  evaluated mutation within its scope, target, ttl, and audience.
- `ExecutionAdapter`: a pure consumer of authorization. It must not mint,
  expand, reinterpret, or bypass authority.
- `InfraLedgerEntry`: append-only lineage record of the mutation and the
  authorization it consumed.

## Forbidden Shortcuts

- UI action directly mutates infrastructure.
- API token alone implies full authority.
- Root token becomes a production authority fallback.
- Agent runtime executes shell or Docker commands without bounded
  authorization.
- Risk score directly allows or denies mutation.
- Execution adapter creates authority from context.
- Logging is treated as optional after the mutation.

## Capability Classes

Infrastructure actions must be represented as capability classes, not as raw
controller methods or endpoint names. Examples:

- `server.delete`
- `server.validate`
- `application.deploy`
- `container.logs.read`
- `container.exec.command`
- `secret.rotate`
- `proxy.restart`

Each capability class must define scope, target, ttl, replay constraints, risk
requirements, and ledger requirements before production use.

## Emergency Mode

Emergency access is allowed only as an explicit constitutional violation path:

- non-default;
- operator-visible;
- ledger-recorded;
- time-bounded;
- non-reusable;
- never silently equivalent to normal authorization.

Emergency mode exists to preserve recoverability, not to create a permanent
backdoor authority layer.

## Consequences

- `PolicyEngine` cannot approve without an identity-bound intent.
- `AgentContainerService::exec()` must evolve from root-token execution to
  `AuthorizationArtifact` consumption.
- `SecurityObservation` is a risk input, not direct authority or automatic ban.
- UI and Livewire components request intents; they do not own authority.
- Infra ledger entries become required mutation lineage, not best-effort logs.
- PRs that add mutation paths must answer:

```text
What bounded authority lineage permits this infra mutation?
```

If that question has no concrete artifact-backed answer, the mutation path is
architecturally invalid.
