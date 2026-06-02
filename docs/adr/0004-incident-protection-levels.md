# Incident Protection Levels

## Context

Coolify needs a foundation for escalating attack/incident levels without letting an automated signal destroy or disconnect infrastructure. Critical levels can require L7 under-attack mode, challenges, rate-limit tightening, DNS failover planning, operator notification, and eventually hoster/provider containment.

## Decision

Protection Levels own assessment and ordered action planning only:

- EdgePolicy owns L7 behavior such as `under_attack`, challenge, and rate limits.
- DNS Steering and DNS Zones own DNS provider changes.
- Server/provider adapters own host-level actions such as isolation or poweroff.
- Incident Protection plans these actions and records ledger events, but does not directly call real provider APIs in this MVP.

The configured levels are `normal`, `elevated`, `high`, `critical`, and `emergency`. `critical` plans EdgePolicy under-attack/challenge actions plus notification. `emergency` may include provider isolation/poweroff actions, but those actions are marked `requires_approval`, `dangerous`, and `dry_run`.

## Safety Model

Provider isolation and poweroff require both an explicit approval flag and an approval token. Without both, execution returns `blocked` and records a `protection.action.blocked` ledger event.

Even with approval, provider actions remain dry-run in local and testing environments. Production execution still requires a future real adapter and must opt out of the default dry-run guard.

## Non-Goals

- No automatic server shutdown.
- No real Hetzner or cloud provider calls.
- No DNS mutation from the incident planner.
- No sidebar or full UI surface in this MVP.
