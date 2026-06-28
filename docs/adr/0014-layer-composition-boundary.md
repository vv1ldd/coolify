# ADR 0014: Adoption of Platform Layer Composition Boundary

## Status

Proposed.

## Scope

This ADR is an **adoption** document, not a canon document.

The composition rules for Realm layers are owned by the platform ADR:

```text
simple-l1
  docs/architecture/layer-composition-boundary-adr-0095.md
  (ADR-0095: Layer Composition Boundary)
```

This document records how the Coolify fork's Realm Operations Console adopts and
applies that canon. It does not restate, extend, or override the composition
axioms.

## Decision

This repository adopts platform **ADR-0095** as the source of truth for layer
composition. The composition axioms live there, not here:

```text
Contract locality
Monotonic meaning
Single primary semantic responsibility
Published contract
Universal review gate
```

### What this repository commits to

1. The Realm Operations Console treats `EvidenceGraph`
   (`EvidenceGraphSchema:v0.3`) and `VerificationReport`
   (`VerifierSemanticBoundary:v0.1`) as **published contracts** in the sense
   defined by ADR-0095.
2. All future Realm Operations work consumes only the published contract of the
   upstream layer, never its internal representation.
3. Local implementations do not redefine the composition axioms. If a change
   appears to require changing an axiom, it is a platform-level architectural
   event and must go through a new platform ADR, not a local one.
4. The forbidden shortcuts of ADR-0095 apply here unchanged:

```text
Evidence -> Decision
EvidenceGraph -> Decision
EvidenceGraph -> Execution
VerificationReport -> Execution
```

### Local mapping to platform layers

| Platform layer (ADR-0095) | Local artifact in this fork |
|---------------------------|-----------------------------|
| Expression | `EvidenceGraph` + `EvidenceGraphSchema:v0.3` |
| Interpretation | `VerificationReport` + `VerifierSemanticBoundary:v0.1` |
| Decision | not implemented in this fork |
| Execution | not implemented in this fork |

The Operations Console remains `Projection(Evidence Graph)` per ADR-0012. It
participates in the expression-visibility path only and publishes no decision or
execution contract.

## Relationship to prior ADRs

- **ADR-0012** — Expression boundary (Operations Console as projection).
- **ADR-0013** — Interpretation boundary (verifier semantic contract).
- **ADR-0014** (this document) — adopts platform **ADR-0095** as the composition
  canon for both.

See [ADR 0012](0012-realm-operations-console-evidence-projection.md) and
[ADR 0013](0013-verifier-semantic-boundary.md).

## Consequences

The composition rules are maintained in exactly one place (platform ADR-0095),
removing the risk of two canonical documents drifting apart. This fork keeps a
thin adoption record that binds its local artifacts to the platform canon and is
reviewed against it.

```text
Canon lives in the platform (ADR-0095).
This fork adopts and applies it.
Meaning flows downstream. Meaning is never rewritten upstream.
```
