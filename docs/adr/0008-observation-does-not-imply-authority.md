# ADR 0008: Observation Does Not Imply Authority

## Status

Accepted for Simple L1 failover and future control-plane automation.

## Context

Simple L1 failover now has four distinct stages:

1. Observation: probe node health and collect evidence.
2. Recommendation: derive an assessment from recent evidence.
3. Decision: authorize or reject a proposed traffic movement.
4. Control action: apply the authorized change through a provider adapter.

Without a hard boundary between these stages, it is tempting to shortcut from
"node unhealthy" directly to "write DNS". That creates flapping risk, weak audit
trails, and makes DNS the source of authority instead of one replaceable control
adapter.

## Decision

Observations may influence recommendations.

Recommendations may influence decisions.

Decisions may authorize control actions.

Observations alone never authorize control actions.

For Simple L1 failover this means:

- `simple_l1_node_observations` stores immutable health evidence.
- `simple_l1_evidence_packages` groups sealed evidence inputs and provides an
  integrity fingerprint.
- `simple_l1_decision_evidence_links` records immutable authority-to-evidence
  references. A decision may reference one or more evidence packages.
- `SimpleL1PanelFailoverService` derives a recommendation from current DNS
  target health and available healthy candidates.
- `simple_l1_failover_decisions` stores the authority outcome, reason,
  evidence package reference, evidence hash, and applied result if a control
  action was taken.
- `simple_l1_control_actions` stores append-only execution facts for provider
  actions such as Cloudflare DNS updates.
- DNS changes are applied only after a decision authorizes promotion.
- A recovered original node does not automatically regain traffic while the
  current target is still healthy.

## Boundaries

The observation plane must not mutate DNS, provider resources, edge policy, or
application routing.

The election plane must not perform provider-specific writes directly. It can
produce decisions and call a control adapter only after the decision says the
action is authorized.

The control adapter is replaceable. Today the adapter is Cloudflare DNS through
`DnsZoneService`; tomorrow it may be a load balancer, tunnel steering, anycast,
BGP announcement, or another traffic-control mechanism.

## Invariants

- Evidence stores facts.
- Links store causality.
- Decisions store authority.
- Actions store execution.
- Health evidence is not authority.
- A recommendation is not authority.
- A decision is the authority boundary for control actions.
- Control actions must be traceable to a decision and an evidence hash.
- Decisions reference evidence packages.
- Evidence packages never reference decisions.
- Evidence references are modeled as separate append-only link records.
- Adding evidence to an existing decision creates a new evidence link; it does
  not rewrite an existing link.
- Evidence packages are immutable after sealing.
- Authority state lives on decisions and may evolve through approval,
  rejection, application, override, or rollback metadata.
- Execution outcomes are append-only control action records.
- Promotion requires evidence that the current target is unhealthy and a
  replacement target is healthy.
- Recovery does not imply promotion.

## Consequences

Failover is no longer a health-check script. It is an observation and election
layer with DNS as one consumer of decisions.

This adds storage and operational history, but it gives operators the ability to
answer:

- What did each node observe?
- What was recommended?
- What decision was made?
- What evidence justified it?
- Which control action, if any, was applied?

The model keeps future control mechanisms replaceable without rewriting the
observation or election layers.
