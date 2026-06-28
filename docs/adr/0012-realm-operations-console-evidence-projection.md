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
