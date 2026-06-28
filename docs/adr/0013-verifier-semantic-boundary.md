# ADR 0013: Verifier Semantic Boundary

## Status

Proposed.

## Scope

Verifier interpretation contract for evidence produced by `EvidenceGraph:v0.3`
and future verifier implementations.

This ADR is a **boundary document**, not a design document. It does not specify
implementation details. It defines the limit through which any future verifier
code must pass.

```text
EvidenceGraph:v0.3
  -> Verifier Semantic Boundary
  -> Future verifier implementations

not:
Future verifier implementation
  -> new meaning of evidence
```

## Context

After v0.3, the Realm Operations evidence graph is a bounded claim language with
causal provenance:

```text
EvidenceGraph
+ EvidenceGraphSchema:v0.3
+ EvidenceGraphValidator
= bounded claim language
```

The graph already defines:

- which claims may be expressed;
- which lineage paths establish origin;
- which relations are legal under the schema.

Production confirmed a structural invariant:

```text
same language
does not require
same facts
```

Independent projections over the same graph grammar produce the same causal
language fingerprint, while local evidence instances may differ.

The next boundary is not graph completeness. The next boundary is **how
verifiers may interpret** claims expressed through that graph.

Without an explicit contract, verification can silently become a second control
plane:

```text
EvidenceGraph
  -> VerificationReport
  -> Recommendation
  -> Action
```

That drift is the most dangerous failure mode after v0.3: when "state checking"
gradually becomes "state governing".

## Decision

The verifier is an **evidence evaluator**, not a **Realm actor**.

```text
EvidenceGraph
  -> Verifier
  -> VerificationReport
```

The verifier consumes:

- `EvidenceGraph`
- `RuntimeObservation`
- `ReplayInput`
- other evidence nodes produced under `EvidenceGraphSchema:v0.3`

The verifier produces:

- `VerificationReport`

The verifier does not produce authority, governance outcomes, or actions.

```text
Verifier is an evaluator,
not an actor.
```

### Allowed semantics

A verifier may:

- inspect evidence;
- evaluate evidence sufficiency;
- compare observations under an explicit comparison contract;
- classify proof state;
- emit a bounded `VerificationReport`.

### Forbidden semantics

A verifier must not:

- grant authority;
- revoke authority;
- mutate Realm state;
- trigger repair;
- elect a leader;
- infer ownership;
- create a governance outcome;
- recommend or execute control-plane actions.

Forbidden report shapes include semantic inflation such as:

```json
{
  "status": "FAILED",
  "action": "isolate_node"
}
```

because that mixes:

```text
verification + policy + execution
```

A bounded report stays in the proof domain:

```json
{
  "status": "BLOCKED",
  "reasons": ["..."]
}
```

Decision and action belong to separate future layers:

```text
VerificationReport
  -> Governance Policy (future, separate)
  -> Action (future, separate)
```

The Operations Console and verifier layer stop at `VerificationReport`.

### Result contract

Verifier output uses `VerificationResult`:

```text
SUPPORTED
  evidence path exists
  proof condition satisfied

BLOCKED
  required path exists
  proof condition not satisfied

UNKNOWN
  evidence insufficient
  conclusion unavailable
```

`UNKNOWN` is a first-class result. The verifier is not a binary oracle.

```text
true / false   (forbidden as sole semantics)

supported / blocked / unknown   (required)
```

### Separation invariant

`INVALID` is not a `VerificationResult`.

They belong to different layers:

```text
GraphValidator -> INVALID
Verifier         -> SUPPORTED | BLOCKED | UNKNOWN
Comparison       -> CONVERGED | DIVERGED | UNKNOWN
```

Meaning:

```text
INVALID
  the claim cannot exist in this graph language

UNKNOWN
  the claim is expressible,
  but proof is incomplete

BLOCKED
  the claim path exists,
  but proof condition fails

SUPPORTED
  the claim path exists,
  and proof condition is satisfied
```

Critical boundaries:

```text
UNKNOWN != INVALID
INVALID != Realm failure
DIVERGED != broken node
```

A verifier must not convert `UNKNOWN` into a governance decision.

### Production invariant

The verifier layer must satisfy:

```text
same verifier semantics
does not require
same local observation
```

Correctness is measured by interpretation boundary, not world-state equality:

```text
Node A: local evidence A -> Verifier Contract -> Result
Node B: local evidence B -> Verifier Contract -> Result

success does not require A == B
success requires same interpretation boundary
```

This extends the v0.3 invariant from graph language to verifier semantics:

```text
same language does not require same facts
same verifier semantics does not require same local observation
```

### Non-goals

This ADR explicitly excludes:

- automatic remediation;
- consensus;
- leader selection;
- authority transfer;
- policy enforcement;
- state mutation;
- new graph relations;
- `EvidenceGraphSchema:v0.4` changes.

Verifier semantics may deepen interpretation over the existing graph. They may
not change graph language without a schema version bump.

### Review question

Use on every verifier-related change:

```text
Does this component evaluate evidence,
or did it start acting on the Realm?
```

If the answer is the second, it is not verifier-layer work.

## Relationship to ADR 0012

ADR 0012 established the Operations Console as `Projection(Evidence Graph)` and
closed v0.3 as bounded claim language with causal provenance.

ADR 0013 extends that discipline to verifier interpretation:

- graph validation checks whether an explanation is structurally honest;
- verifier evaluation checks whether evidence supports a bounded proof claim;
- neither layer decides Realm truth or governance action.

```text
GraphValidator validates the map.
Verifier validates the interpretation.
Console exposes navigation.
Human decides action.
```

## Relationship to ADR 0008

ADR 0008 established that observation does not imply authority for control
actions.

ADR 0013 applies the same principle to verifier output:

- verification may inform understanding;
- verification does not authorize repair, election, promotion, or mutation;
- `VerificationReport` is evidence, not a control command.

## Consequences

Future verifier implementations (Rust shadow verifier, WASM, local mobile
verifier, additional replay engines) must produce bounded `VerificationReport`
artifacts that pass this boundary.

Adding verifier semantics does not require `EvidenceGraphSchema:v0.4` unless a
new **claim type** or **relation meaning** is introduced.

Deepening proof interpretation over the existing lineage path remains compatible
with v0.3:

```text
Artifact
  -> DeploymentEvidence
  -> RuntimeObservation
  -> HistoryAnchor
  -> ReplayInput
  -> VerificationReport
  -> SemanticHealth (projection only)
```

The verifier layer may grow richer proof classification. It may not grow
authority.

Final boundary:

```text
The system does not become the source of truth.
It becomes a place where unsupported claims are harder to express.
```

The verifier's role after v0.3:

```text
It does not determine reality.
It classifies whether the available evidence path supports a bounded claim.
```
