# Provider Control Adapters

## Context

Incident Protection can plan provider-level host actions, but those actions are dangerous and must remain approval-gated. The first supported host providers for VPS/VDS control are Selectel VDS and Hostinger VPS.

## Decision

Provider control is implemented behind `ProviderServerActionAdapter` and resolved by provider key:

- `selectel_vds` uses Selectel VDS API v1 (`https://api.vscale.io/v1`) with `X-Token` authentication.
- `hostinger_vps` uses Hostinger VPS API v1 (`https://developers.hostinger.com/api/vps/v1`) with Bearer authentication.

Adapters return DTOs (`ProviderServer`, `ProviderActionPlan`, `ProviderActionResult`) that intentionally exclude API tokens. Exceptions report provider and HTTP status only, not response bodies or request headers.

Supported actions:

- Selectel VDS: list servers, inspect server, start, reboot, poweroff.
- Hostinger VPS: list virtual machines, inspect virtual machine, start, reboot, poweroff.
- Hostinger VPS isolation: supported only when `HOSTINGER_VPS_ISOLATION_FIREWALL_ID` points to a preconfigured firewall. The adapter activates that firewall but does not create firewall rules during an incident.
- Selectel VDS isolation: not implemented because the VDS API v1 documentation reviewed for this change did not expose firewall isolation endpoints.

## Safety Model

Provider adapters are callable by backend services, but Incident Protection execution remains the safety gate:

- Dangerous actions require both `approved: true` and a non-empty `approval_token`.
- Local and testing environments always keep dangerous provider actions as dry-runs, even with approval.
- Production execution still requires `force_provider_execution` and `SOVEREIGN_INCIDENT_PROVIDER_DRY_RUN=false`.
- Tests must use `Http::fake`; no provider test may call real Selectel or Hostinger APIs.

Secrets are not stored in config. Provider API tokens live only in encrypted `cloud_provider_tokens.token` records.
