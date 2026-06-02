# ADR 0011: Resource Continuity Control Plane

## Status

Accepted for Simple L1, marketplace, API, checkout, and provider gateway routing.

## Context

Simple L1 failover introduced a useful pattern: observe resource health, derive
evidence, decide whether routing should change, and apply a control action. That
pattern is not specific to Simple L1. Marketplace, APIs, checkout, and provider
gateways have the same continuity problem.

The important distinction is routing layer:

- L3/L4 failover: DNS points to a healthy edge node.
- L7 failover: edge mesh routes to a healthy backend resource.

DNS failover is coarse and useful when an edge node, region, or ingress is down.
L7 failover is preferred for application containers because it avoids DNS
propagation delay and can use readiness, gradual routing, and arbitration.

## Decision

Continuity is modeled around routable resources, not around Simple L1.

Generic chain:

```text
Resource Intent
  -> Projection
  -> Observation
  -> Reconciliation Assessment
  -> Arbitration Decision
  -> Control Action
```

Resource types include:

- `simple_l1`
- `marketplace`
- `api`
- `checkout`
- `provider_gateway`
- `application`
- `service_application`

DNS is one adapter. Edge runtime is another adapter.

## Invariants

- Container failure does not necessarily imply DNS change.
- L7 routing is preferred for backend resource failover.
- DNS failover is reserved for edge/region/ingress failures.
- Resource observations do not imply routing authority.
- Resource arbitration decisions authorize routing changes.
- Rollback is a new control action, not history rewrite.

## Consequences

`SimpleL1PanelFailoverService` remains valid as the first specialized consumer,
but the long-term bounded context is Resource Continuity Control Plane.

Future implementation can introduce:

- `ResourceRoutingPolicy`
- `ResourceProjection`
- `ResourceObservation`
- `ResourceArbitration`
- `ResourceControlAction`

These can reuse the Edge Control Plane projection, execution, observation, and
consensus layers.
