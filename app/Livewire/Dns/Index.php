<?php

namespace App\Livewire\Dns;

use App\Models\CloudflareSetting;
use App\Models\ControlPlanePeer;
use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;
use App\Models\ResourceArbitrationDecision;
use App\Models\ResourceReconciliationAssessment;
use App\Models\ResourceRoutingPolicy;
use App\Services\DomainInventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Index extends Component
{
    public bool $tokenConfigured = false;

    public ?string $lastValidatedAt = null;

    public function mount(): void
    {
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        if (! $this->settingsTableExists()) {
            $this->tokenConfigured = false;
            $this->lastValidatedAt = null;

            return;
        }

        $setting = CloudflareSetting::whereTeamId(currentTeam()->id)->first();
        $this->tokenConfigured = (bool) $setting;
        $this->lastValidatedAt = $setting?->last_validated_at?->diffForHumans();
    }

    private function settingsTableExists(): bool
    {
        return Schema::hasTable('cloudflare_settings');
    }

    public function render()
    {
        return view('livewire.dns.index', [
            'domainEntries' => $this->domainEntries(),
            'simpleL1' => $this->simpleL1Summary(),
            'edgeNodes' => $this->edgeNodeSummary(),
            'resourceContinuity' => $this->resourceContinuitySummary(),
        ]);
    }

    private function domainEntries(): Collection
    {
        $teamId = currentTeam()?->id;
        if (! $teamId) {
            return collect();
        }

        return app(DomainInventoryService::class)
            ->forTeam($teamId)
            ->filter(fn (array $entry): bool => collect(data_get($entry, 'uses', []))
                ->contains(fn (array $use): bool => in_array(data_get($use, 'resource_type'), ['application', 'service_application'], true)))
            ->map(fn (array $entry): array => array_merge($entry, [
                'adapter_mode' => $this->adapterModeForDomain((string) data_get($entry, 'domain'), data_get($entry, 'dns_zones', [])),
                'projection' => $this->projectionSummary((string) data_get($entry, 'domain'), EdgeProjection::ADAPTER_TRAEFIK),
                'last_control_action' => $this->controlActionSummary((string) data_get($entry, 'domain')),
            ]))
            ->take(8)
            ->values();
    }

    private function simpleL1Summary(): array
    {
        $teamId = currentTeam()?->id;
        if (! $teamId || ! Schema::hasTable('dns_steering_policies')) {
            return [
                'available' => false,
                'domain' => (string) env('SIMPLE_L1_DOMAIN', 'simplel1.online'),
                'message' => 'Simple L1 DNS steering is not configured yet.',
            ];
        }

        $policy = DnsSteeringPolicy::query()
            ->with('zone')
            ->where('team_id', $teamId)
            ->where('resource_type', 'simple_l1')
            ->orderByDesc('enabled')
            ->orderBy('id')
            ->first();
        $domain = $policy?->domain ?: (string) env('SIMPLE_L1_DOMAIN', 'simplel1.online');
        $record = DnsRecord::query()
            ->where('type', 'A')
            ->where('name', $domain)
            ->whereHas('zone', fn ($query) => $query->where('team_id', $teamId))
            ->first();

        return [
            'available' => (bool) $policy,
            'domain' => $domain,
            'current_target' => $record?->content,
            'policy_enabled' => (bool) $policy?->enabled,
            'strategy' => $policy?->strategy,
            'zone_name' => data_get($policy, 'zone.name'),
            'zone_uuid' => data_get($policy, 'zone.uuid'),
            'candidate_count' => collect($policy?->candidate_nodes ?: [])->count(),
            'adapter_mode' => $this->adapterModeForDomain($domain, $policy?->zone ? [['name' => data_get($policy, 'zone.name')]] : []),
            'projection' => $this->projectionSummary($domain),
            'last_control_action' => $this->controlActionSummary($domain),
            'message' => $policy ? null : 'Run the Sovereign Simple L1 bootstrap to attach this domain to Cloudflare steering.',
        ];
    }

    private function edgeNodeSummary(): array
    {
        if (! Schema::hasTable('control_plane_peers')) {
            return [
                'available' => false,
                'capabilities' => [],
            ];
        }

        $teamId = currentTeam()?->id;
        if (! $teamId) {
            return [
                'available' => false,
                'capabilities' => [],
            ];
        }

        $peers = ControlPlanePeer::query()
            ->where('team_id', $teamId)
            ->where('status', ControlPlanePeer::STATUS_ONLINE)
            ->get();
        $capabilities = collect([
            ControlPlanePeer::CAPABILITY_EDGE_RUNTIME,
            ControlPlanePeer::CAPABILITY_AUTHORITATIVE_DNS,
            ControlPlanePeer::CAPABILITY_HEALTH_OBSERVER,
            ControlPlanePeer::CAPABILITY_CONTROL_PLANE,
        ])->mapWithKeys(fn (string $capability): array => [
            $capability => $peers->filter(fn (ControlPlanePeer $peer): bool => $peer->providesCapability($capability))->count(),
        ]);

        return [
            'available' => true,
            'online_peers' => $peers->count(),
            'capabilities' => $capabilities->all(),
        ];
    }

    private function adapterModeForDomain(string $domain, mixed $dnsZones): string
    {
        $hasCloudflare = collect($dnsZones)->isNotEmpty();
        $hasAuthoritative = Schema::hasTable('edge_projections') && EdgeProjection::query()
            ->where('team_id', currentTeam()?->id)
            ->where('domain', $domain)
            ->where('adapter', EdgeProjection::ADAPTER_AUTHORITATIVE_NS)
            ->exists();

        return match (true) {
            $hasCloudflare && $hasAuthoritative => 'hybrid',
            $hasAuthoritative => 'authoritative-ready',
            $hasCloudflare => 'cloudflare',
            default => 'unmatched',
        };
    }

    private function projectionSummary(string $domain, ?string $adapter = null): ?array
    {
        if (! Schema::hasTable('edge_projections')) {
            return null;
        }

        $projection = EdgeProjection::query()
            ->where('team_id', currentTeam()?->id)
            ->where('domain', $domain)
            ->when($adapter, fn ($query) => $query->where('adapter', $adapter))
            ->latest('generated_at')
            ->first();

        if (! $projection) {
            return null;
        }

        return [
            'uuid' => $projection->uuid,
            'adapter' => $projection->adapter,
            'type' => $projection->projection_type,
            'status' => $projection->status,
            'version' => $projection->projection_version,
            'projection_short' => substr($projection->projection_hash, 0, 12),
            'drift_state' => $projection->driftState(),
            'intent_drift_status' => $projection->intent_drift_status,
            'observation_quorum_status' => $projection->observation_quorum_status,
            'observation_confidence' => $projection->observation_confidence,
            'required_capabilities' => $projection->required_capabilities ?: [],
        ];
    }

    private function controlActionSummary(string $domain): ?array
    {
        if (! Schema::hasTable('edge_control_actions')) {
            return null;
        }

        $action = EdgeControlAction::query()
            ->where('team_id', currentTeam()?->id)
            ->where('domain', $domain)
            ->latest('executed_at')
            ->first();

        if (! $action) {
            return null;
        }

        return [
            'uuid' => $action->uuid,
            'adapter' => $action->adapter,
            'status' => $action->status,
            'action_type' => $action->action_type,
            'executed_at' => $action->executed_at?->diffForHumans(),
        ];
    }

    private function resourceContinuitySummary(): array
    {
        if (! Schema::hasTable('resource_routing_policies')) {
            return [
                'available' => false,
                'policies' => [],
            ];
        }

        $teamId = currentTeam()?->id;
        if (! $teamId) {
            return [
                'available' => false,
                'policies' => [],
            ];
        }

        $policies = ResourceRoutingPolicy::query()
            ->where('team_id', $teamId)
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(function (ResourceRoutingPolicy $policy): array {
                $decision = Schema::hasTable('resource_arbitration_decisions')
                    ? ResourceArbitrationDecision::query()
                        ->where('resource_routing_policy_id', $policy->id)
                        ->latest('decided_at')
                        ->first()
                    : null;
                $assessment = Schema::hasTable('resource_reconciliation_assessments')
                    ? ResourceReconciliationAssessment::query()
                        ->where('resource_routing_policy_id', $policy->id)
                        ->latest('assessed_at')
                        ->first()
                    : null;

                return [
                    'resource_type' => $policy->resource_type,
                    'resource_uuid' => $policy->resource_uuid,
                    'domain' => $policy->domain,
                    'routing_layer' => $policy->routing_layer,
                    'enabled' => $policy->enabled,
                    'candidate_count' => collect($policy->candidate_backends ?: [])->count(),
                    'assessment_hash' => $assessment ? substr($assessment->assessment_hash, 0, 12) : null,
                    'assessment_severity' => $assessment?->severity,
                    'conflict_count' => collect($assessment?->conflicts ?: [])->count(),
                    'decision' => $decision?->decision,
                    'reason' => $decision?->reason,
                    'authority_scope' => $decision?->authority_scope,
                    'authority_actor' => $decision?->authority_actor,
                    'authority_basis' => $decision?->authority_basis,
                    'decision_hash' => $decision ? substr((string) $decision->decision_hash, 0, 12) : null,
                ];
            })
            ->values()
            ->all();

        return [
            'available' => true,
            'policies' => $policies,
        ];
    }
}
