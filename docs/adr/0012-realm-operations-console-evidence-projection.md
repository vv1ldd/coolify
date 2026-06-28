# ADR 0012: Realm Operations Console as Evidence Graph Projection

## Status

Accepted for Realm Operations Console (v0 First Slice and all future console
evolution).

## Context

The Sovereign Coolify fork includes a Realm Operations Console: a read-only
operator surface that aggregates Realm evidence without becoming a hidden
authority layer.

A production incident demonstrated that process health and semantic health are
not the same signal. A container can be healthy while replay or shadow
verification fails. A single green "Healthy" indicator therefore misleads
operators and encourages infrastructure-level repairs for semantic-level
problems.

The first console slice established the boundary:

```text
Observe -> Aggregate -> Display

not:
Observe -> Decide -> Mutate
```

Future console work (Evidence Timeline, lineage views, multi-verifier
comparison) must preserve this boundary. Without an explicit architectural
contract, convenience features can slowly turn the console into a second source
of truth.

## Decision

The Realm Operations Console is defined as:

```text
Operations Console = Projection(Evidence Graph)
```

The console projects an existing evidence graph for humans. It does not create,
mutate, or substitute evidence. It does not define protocol meaning. It does not
execute transitions. It does not validate truth.

### Responsibility model

```text
Protocol defines.
Runtime executes.
Evidence records.
Verifier validates.
Operations Console projects.
Human decides.
```

Each layer uses a unique verb. No layer may assume another layer's verb.

### Architectural contract (mandatory invariants)

These laws must never be violated by console code, UI, or aggregation services.

**Law of Provenance**

Every visible conclusion must be traceable to evidence.

**Law of Reflection**

The UI reflects the evidence model. The UI does not invent its own model.

Data flows:

```text
RealmOperationsSnapshot
  -> TimelineItem
  -> UI
```

not:

```text
UI
  -> Own interpretation
  -> Status
```

**Law of Conservatism**

```text
No evidence          -> UNKNOWN
Evidence confirms    -> OK
Evidence contradicts -> FAIL
```

The console must not infer "probably OK" from absent evidence.

**Law of Attribution**

Every visible conclusion must identify its authority.

**Law of Non-Substitution**

The Console may summarize evidence. The Console must never substitute evidence.

A summarized conclusion such as `Semantic Health: OK` is valid only when the
operator can expand it to primary evidence without information loss.

**Law of Monotonicity**

Evidence is append-only. Conclusions may be refined by newer evidence while
earlier evidence remains preserved.

The console must not rewrite sealed evidence or prior evidence references.

**Law of Completeness**

When presenting a conclusion, the console should expose the evidence path needed
to explain that conclusion.

Example:

```text
Semantic Health: OK
 └── Shadow Verification: OK
      └── Replay: OK
           └── Runtime Observation
                └── Deployment
                     └── Artifact
```

**Law of Evidence Quality**

The console may expose uncertainty, but it must not convert missing evidence
into confidence.

```text
UNKNOWN + explanation  >  false OK
```

Never fix a red indicator by changing the indicator. Fix the evidence source.

**Law of Evidence Separation**

Process evidence, runtime evidence, and semantic evidence must remain
distinguishable. The console must not collapse them into a single aggregated
status such as `overall_status = green`.

```text
Process Health   = execution surface responds
Runtime Reality  = Realm-derived state is observable
Semantic Health  = meaning is independently verified
```

**Law of Projection Closure**

Every projection must be complete enough that the UI never needs to derive
protocol meaning. `RealmOperationsService` is responsible for producing
semantically complete projections; the UI must not reconstruct evidence.

If a Blade template needs an `if`-statement about meaning, the projection is
incomplete.

**Law of Comparison Boundaries**

A comparison may only produce claims supported by the comparison contract.

**Law of Derived Evidence**

Derived evidence may explain source evidence. Derived evidence may not exceed
source evidence.

**Law of Contract Visibility**

Every derived conclusion must expose the contract that allowed the conclusion.

**Law of Scope Preservation**

A derived conclusion must not claim more than the scope of its evidence.

Example:

```text
CONVERGED
scope: runtime_observation_equivalence
```

does not imply global Realm health. It only claims that compared runtime fields
matched under an explicit comparison contract.

**Law of Independent Projection**

Independent projections over the same evidence and the same comparison contract
must produce the same derived conclusion.

```text
same evidence + same contract + different conclusion
  -> projection defect
```

A disagreement between projection instances is not Realm disagreement. It means
the projection logic is no longer deterministic over evidence.

**Law of Explanation Preservation**

A derived conclusion should preserve the path needed to explain how it was
produced.

It is not enough to keep only:

```json
{
  "result": "DIVERGED"
}
```

A projection should also expose the inputs and contract that allowed the result:

```json
{
  "result": "DIVERGED",
  "derived_from": [
    "runtime-observation-lena",
    "runtime-observation-lena-1-gcl"
  ],
  "contract": "RuntimeComparisonContract:v0.1"
}
```

**Law of Causal Navigation**

A projection should allow navigation from conclusion back to its supporting
evidence.

```text
Explanation Preservation = the path exists
Causal Navigation        = the path is traversable
```

Every conclusion node must expose derivation edges such as `derived_from`,
`evaluated_by`, or `blocked_by`. Unknown conclusions are not dead ends; they
should identify the missing proof path.

```text
SemanticHealth UNKNOWN
  -> blocked_by VerificationReport missing
  -> blocked_by Replay not executed
  -> derived_from Runtime history evidence
```

The UI boundary is:

```text
Blade may traverse.
Blade may not infer.
```

### Evidence graph language (v0.3)

v0 made evidence visible. v0.1 made evidence comparable. v0.2 made evidence
navigable. v0.3 makes the evidence graph accountable for its own language.

```text
v0    Evidence visibility
v0.1  Evidence comparison
v0.2  Evidence explanation / navigation
v0.3  Evidence language validity
```

The pipeline becomes:

```text
EvidenceGraph (v0.2)
  -> EvidenceGraphSchema:v0.3
  -> EvidenceGraphValidator
  -> GraphValidationResult
  -> Projection
```

The validator checks whether an explanation is expressed in legal language. It
does not check Realm correctness.

```text
VALID graph = the explanation structure is honest
VALID graph != VALID Realm
VALID graph != VALID protocol state
VALID graph != VALID authority decision
```

**Law of Typed Causality**

Every edge declares an `edge_kind`. Lineage and explanation are distinct edge
kinds and may not be silently mixed.

```text
lineage     = origin     (how a fact came to exist)
explanation = justification (how a conclusion is supported)

origin != justification
```

**Law of Edge Semantics**

A `relation` is legal only for its declared `edge_kind`, under the schema.

```text
observed_from + lineage      -> legal
supports      + lineage      -> INVALID_EDGE_SEMANTICS
```

**Law of Explicit Derivation**

A conclusion node (for example `SemanticHealth`, `MeshConvergenceEvidence`) must
expose an explicit derivation or blocking path.

```text
SemanticHealth without derivation path -> MISSING_DERIVATION_PATH
```

No hidden conclusion.

**Law of Declared Authority**

An authority-bearing node must declare its authority domain. An orphan authority
is rejected.

```text
RuntimeObservation authority UNKNOWN -> MISSING_AUTHORITY_DECLARATION
```

No orphan authority.

**Law of Non-Decision (Control-Plane Closure)**

The graph may explain. The graph may not command. Control-plane relations are
forbidden by the schema and rejected by the validator.

```text
grants_authority -> FORBIDDEN_EDGE_RELATION
repairs, elects, promotes, synchronizes, decides -> rejected
```

This closes the most dangerous degradation path:

```text
evidence graph -> control plane    (forbidden)

Graph explains.
Graph does not command.
```

**Law of Validator Non-Participation**

The validator is not a participant in the proof. It adds no meaning, repairs no
graph, and adds no edges.

```text
GraphValidator does not add meaning.
GraphValidator checks that meaning is expressed legally.
```

An invalid graph does not make the system invalid. It only means the explanation
artifact is malformed.

```text
invalid graph -> the explanation artifact is malformed
invalid graph -> NOT system invalid
```

**Law of Validation as Evidence**

`GraphValidationResult` is itself evidence: the graph is `evaluated_by` the
validator. But it proves only structure, never reality.

```text
EvidenceGraph -- evaluated_by --> GraphValidationResult

GraphValidationResult proves graph structure, not reality.
```

**Law of Independent Validation**

The Law of Independent Projection extends to the validation layer. Independent
validators over the same graph must produce the same structural verdict.

```text
same graph + same schema_ref + same validator_version
  -> same GraphValidationResult
```

`validated_at` is observation metadata, not part of the structural verdict. A
disagreement between validator instances is a validator defect, not Realm
disagreement.

**Law of Versioned Graph Semantics**

The graph declares the `schema_ref` used to interpret its relations. Graph
language semantics may change only by bumping the schema version (for example
`EvidenceGraphSchema:v0.4`). No semantic drift without a schema version bump.

```text
EvidenceGraph without schema_ref -> MISSING_OR_MISMATCHED_SCHEMA_REF
```

The v0.3 boundary, in one line:

```text
Graph may reject dishonest explanation.
Graph may not decide reality.
```

The full responsibility chain after v0.3:

```text
Protocol defines meaning.
Runtime reports reality.
Evidence preserves facts.
Schema defines expression.
Validator protects structure.
Verifier proves interpretation.
Projection exposes navigation.
Human decides.
```

### Evolution principle

This is a product-development rule, not a data invariant.

**Law of Explanatory Growth**

Every new feature must increase understanding without increasing authority.

Short test:

```text
Does this feature increase understanding or increase authority?
```

- understanding -> candidate for Operations Console
- authority -> Protocol, Runtime, Verifier, or protocol-aware workflow

### Semantic ownership

`authority` and `source` are independent fields on timeline items and snapshot
entries.

```text
authority = who is entitled to make the claim
source    = where the console obtained the claim
```

Examples:

| Assertion           | Authority  | Source                 |
| ------------------- | ---------- | ---------------------- |
| semantic_health     | Verifier   | Evidence package       |
| package_fingerprint | Protocol   | Evidence package       |
| image_digest        | Deployment | Deployment metadata    |
| runtime_state       | Runtime    | Runtime observation    |
| UI grouping/layout  | Console    | Console projection     |

`source` is not a physical storage location. It is the semantic owner of the
assertion. Physical evidence locations are referenced through `evidence_ref`.

### Timeline item shape

Console projections should use a uniform item shape:

```text
TimelineItem
  kind
  title
  value
  trust_state: OK | UNKNOWN | FAIL
  authority
  source
  observed_at
  evidence_ref
  depends_on[]
  details
```

`trust_state` is derived conservatively from evidence by the aggregation service,
not invented by the UI.

### Evidence chains

The console distinguishes two complementary chains.

**Evidence Production** answers: what facts were collected?

```text
Artifact
  -> Conformance
  -> Deployment
  -> Runtime Observation
```

**Evidence Validation** answers: do those facts support the current conclusion?

```text
Replay
  -> Shadow Verification
  -> Semantic Health
```

These chains must not be visually merged into a single undifferentiated status.

### Feature ownership boundary

```text
Expand the evidence graph
  -> Protocol, Runtime, Verifier

Expand the projection of the evidence graph
  -> Operations Console
```

Examples:

| Feature                    | Layer                              |
| -------------------------- | ---------------------------------- |
| New verifier               | Verifier                           |
| New evidence package       | Runtime / Verifier                 |
| New replay semantics       | Protocol                           |
| Evidence timeline          | Operations Console                 |
| Lineage visualization      | Operations Console                 |
| Multi-verifier comparison  | Operations Console                 |
| Mesh convergence evidence| Operations Console                 |
| Evidence graph navigation  | Operations Console                 |
| Evidence graph schema/validation | Operations Console           |
| Replay request workflow    | Protocol-aware workflow            |

### Review checklist

Use on every Operations Console PR:

```text
□ Is every visible conclusion traceable to evidence?
□ Does the UI reflect rather than invent?
□ Can UNKNOWN remain UNKNOWN?
□ Is authority explicitly identified?
□ Is evidence summarized but never substituted?
□ Is evidence append-only?
□ Does this feature increase understanding?
□ Does this feature avoid increasing authority?
```

### Architectural smells

The following are violations of one or more laws:

- UI computes semantic truth
- UI overrides verifier output
- UI invents `trust_state`
- UI silently upgrades `UNKNOWN` -> `OK`
- UI rewrites evidence
- Console stores panel-owned Realm history that competes with protocol history
- Console buttons that mutate history, ledger state, or protocol meaning without
  a protocol-aware workflow that produces new evidence

## Relationship to ADR 0008

ADR 0008 established that observation does not imply authority for failover
control actions.

ADR 0012 extends the same discipline to Realm Operations Console projections:

- runtime observation does not imply semantic health
- deployment success does not imply verification success
- summarized console output does not substitute sealed evidence

## Consequences

The console may grow rich explainability features (timelines, drill-downs,
lineage, comparisons) without becoming an authority layer, as long as it
remains `Projection(Evidence Graph)`.

New verifier implementations (Rust, WASM, Go, Swift, mobile local verifier) add
new evidence producers. The console contract does not change; it gains new
evidence nodes and projection paths.

Protocol-aware operator actions belong in separate workflows that create
evidence through accepted transitions, replay, and verification. They do not
belong as direct mutation buttons in the Operations Console.

The console's purpose is not control. Its purpose is to make the reproducible
trust chain legible so a human can decide with explicit evidence.
