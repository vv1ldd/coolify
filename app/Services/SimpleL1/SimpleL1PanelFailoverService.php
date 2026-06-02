<?php

namespace App\Services\SimpleL1;

use App\Models\ControlPlanePeer;
use App\Models\DnsSteeringPolicy;
use App\Models\SimpleL1ControlAction;
use App\Models\SimpleL1DecisionEvidenceLink;
use App\Models\SimpleL1EvidencePackage;
use App\Models\SimpleL1FailoverDecision;
use App\Models\SimpleL1NodeObservation;
use App\Services\Dns\DnsSteeringPolicyService;
use App\Services\Dns\DnsZoneService;
use Illuminate\Support\Facades\Schema;

class SimpleL1PanelFailoverService
{
    public function __construct(
        private readonly DnsSteeringPolicyService $steering,
        private readonly DnsZoneService $dnsZones,
    ) {}

    public function evaluate(int $teamId, ?string $domain = null, bool $apply = false): array
    {
        $policy = $this->policy($teamId, $domain);
        if (! $policy) {
            return [
                'ok' => false,
                'status' => 'not_configured',
                'message' => 'Simple L1 DNS steering policy was not found.',
                'apply_requested' => $apply,
                'applied' => false,
            ];
        }

        $plan = $this->steering->planForPolicy($policy);
        $current = $this->currentARecord($plan);
        $currentIp = data_get($current, 'content');
        $currentNode = $this->nodeForIp($plan, $currentIp);
        $targetNode = $this->targetNode($plan);
        $targetIp = data_get($targetNode, 'ip');
        $currentHealthy = is_array($currentNode) ? (bool) data_get($currentNode, 'healthy') : null;
        $canPromote = (bool) data_get($plan, 'can_apply')
            && filled($targetIp)
            && $targetIp !== $currentIp
            && ($currentIp === null || $currentHealthy === false);
        $panelNodes = $this->panelNodes($teamId, $plan);
        $observations = $this->recordObservations($teamId, $policy->domain, $panelNodes);
        $evidencePackage = $this->recordEvidencePackage($teamId, $policy, $currentIp, $panelNodes, $observations, $plan);
        $decision = $this->recordDecision($teamId, $policy, $currentIp, $currentHealthy, $targetIp, $canPromote, $panelNodes, $plan, $evidencePackage);
        $this->recordDecisionEvidenceLink($teamId, $decision, $evidencePackage);

        $result = [
            'ok' => true,
            'status' => $this->status($currentIp, $currentHealthy, $targetIp, $canPromote),
            'apply_requested' => $apply,
            'applied' => false,
            'domain' => $policy->domain,
            'record_name' => data_get($plan, 'record_name'),
            'current_ip' => $currentIp,
            'current_healthy' => $currentHealthy,
            'target_ip' => $targetIp,
            'target_node' => $targetNode,
            'can_promote' => $canPromote,
            'panel_nodes' => $panelNodes,
            'observations' => $observations,
            'evidence_package' => $evidencePackage?->only(['uuid', 'evidence_hash', 'sealed_at']),
            'decision' => $decision?->only(['uuid', 'recommendation', 'reason', 'evidence_hash', 'simple_l1_evidence_package_id', 'applied_at']),
            'plan' => $plan,
        ];

        if ($apply && $canPromote) {
            $result['applied'] = $this->steering->applyPlan($policy, $this->dnsZones, $plan);
            $result['status'] = 'promoted';
            $decision?->update([
                'applied_result' => $result['applied'],
                'applied_by' => 'sovereign:simple-l1-failover',
                'applied_at' => now(),
            ]);
            $controlAction = $this->recordControlAction($teamId, $policy, $decision, $plan, $result['applied']);
            $result['evidence_package'] = $evidencePackage?->refresh()->only(['uuid', 'evidence_hash', 'sealed_at']);
            $result['decision'] = $decision?->refresh()->only(['uuid', 'recommendation', 'reason', 'evidence_hash', 'simple_l1_evidence_package_id', 'applied_at']);
            $result['control_action'] = $controlAction?->only(['uuid', 'action_type', 'adapter', 'status', 'executed_at']);
        }

        return $result;
    }

    private function policy(int $teamId, ?string $domain): ?DnsSteeringPolicy
    {
        return DnsSteeringPolicy::query()
            ->with(['zone.records', 'application'])
            ->where('team_id', $teamId)
            ->where('resource_type', 'simple_l1')
            ->when($domain, fn ($query) => $query->where('domain', $domain))
            ->orderByDesc('enabled')
            ->orderBy('id')
            ->first();
    }

    private function currentARecord(array $plan): ?array
    {
        return collect(data_get($plan, 'current_records', []))
            ->first(fn (array $record): bool => data_get($record, 'type') === 'A');
    }

    private function targetNode(array $plan): ?array
    {
        return collect(data_get($plan, 'selected_nodes', []))
            ->first(fn (array $node): bool => filled(data_get($node, 'ip')));
    }

    private function nodeForIp(array $plan, ?string $ip): ?array
    {
        if (! filled($ip)) {
            return null;
        }

        return collect(data_get($plan, 'candidate_nodes', []))
            ->first(fn (array $node): bool => data_get($node, 'ip') === $ip);
    }

    private function status(?string $currentIp, ?bool $currentHealthy, ?string $targetIp, bool $canPromote): string
    {
        if (! filled($currentIp)) {
            return $canPromote ? 'record_missing_promotable' : 'record_missing';
        }

        if ($currentHealthy === false && $canPromote) {
            return 'active_unhealthy_promotable';
        }

        if ($currentHealthy === false) {
            return 'active_unhealthy_no_target';
        }

        if ($currentHealthy === true) {
            return 'active_healthy';
        }

        return filled($targetIp) ? 'active_unknown' : 'no_healthy_candidates';
    }

    private function panelNodes(int $teamId, array $plan): array
    {
        $peers = ControlPlanePeer::query()
            ->where('team_id', $teamId)
            ->whereNotNull('public_ip')
            ->orderBy('id')
            ->get()
            ->keyBy('public_ip');

        return collect(data_get($plan, 'candidate_nodes', []))
            ->map(function (array $node) use ($peers): array {
                $ip = data_get($node, 'ip') ?: data_get($node, 'ipv6');
                $peer = $ip ? $peers->get($ip) : null;

                return [
                    'name' => data_get($node, 'name') ?: $peer?->name,
                    'ip' => $ip,
                    'healthy' => (bool) data_get($node, 'healthy'),
                    'health_source' => data_get($node, 'health_source'),
                    'health_url' => data_get($node, 'health_url'),
                    'peer_uuid' => $peer?->uuid,
                    'peer_status' => $peer?->freshnessStatus(),
                    'region' => $peer?->region,
                ];
            })
            ->values()
            ->all();
    }

    private function recordObservations(int $teamId, string $domain, array $panelNodes): array
    {
        if (! Schema::hasTable('simple_l1_node_observations')) {
            return [];
        }

        return collect($panelNodes)
            ->map(function (array $node) use ($teamId, $domain): array {
                $observation = SimpleL1NodeObservation::create([
                    'team_id' => $teamId,
                    'domain' => $domain,
                    'observed_at' => now(),
                    'observer_node' => gethostname() ?: null,
                    'target_node' => data_get($node, 'name'),
                    'target_ip' => data_get($node, 'ip'),
                    'status' => data_get($node, 'healthy') ? 'healthy' : 'unhealthy',
                    'latency_ms' => data_get($node, 'latency_ms'),
                    'http_code' => data_get($node, 'http_code'),
                    'health_source' => data_get($node, 'health_source'),
                    'health_url' => data_get($node, 'health_url'),
                    'evidence' => $node,
                ]);

                return $observation->only(['uuid', 'target_node', 'target_ip', 'status', 'observed_at']);
            })
            ->values()
            ->all();
    }

    private function recordEvidencePackage(int $teamId, DnsSteeringPolicy $policy, ?string $currentIp, array $panelNodes, array $observations, array $plan): ?SimpleL1EvidencePackage
    {
        if (! Schema::hasTable('simple_l1_evidence_packages')) {
            return null;
        }

        $metadata = [
            'schema' => 'simple_l1.failover.evidence_package.v1',
            'source' => 'simple_l1_panel_failover',
            'policy_uuid' => $policy->uuid,
            'record_name' => data_get($plan, 'record_name'),
            'current_target' => $currentIp,
            'candidate_nodes' => $panelNodes,
        ];
        $payloadForHash = [
            'observations' => $observations,
            'metadata' => $metadata,
        ];

        return SimpleL1EvidencePackage::create([
            'team_id' => $teamId,
            'domain' => $policy->domain,
            'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
            'evidence_hash' => hash('sha256', $this->canonicalJson($payloadForHash)),
            'observations' => $observations,
            'metadata' => $metadata,
            'sealed_at' => now(),
        ]);
    }

    private function recordDecision(int $teamId, DnsSteeringPolicy $policy, ?string $currentIp, ?bool $currentHealthy, ?string $targetIp, bool $canPromote, array $panelNodes, array $plan, ?SimpleL1EvidencePackage $evidencePackage): ?SimpleL1FailoverDecision
    {
        if (! Schema::hasTable('simple_l1_failover_decisions')) {
            return null;
        }

        $recommendation = $this->recommendation($currentIp, $currentHealthy, $targetIp, $canPromote);
        $reason = $this->status($currentIp, $currentHealthy, $targetIp, $canPromote);
        $evidence = [
            'current_ip' => $currentIp,
            'current_healthy' => $currentHealthy,
            'target_ip' => $targetIp,
            'can_promote' => $canPromote,
            'panel_nodes' => $panelNodes,
            'plan_reason' => data_get($plan, 'reason'),
            'plan_reasons' => data_get($plan, 'reasons', []),
            'recommendation_snapshot' => [
                'recommendation' => $recommendation,
                'reason' => $reason,
                'previous_target' => $currentIp,
                'new_target' => $canPromote ? $targetIp : null,
            ],
            'evidence_package' => $evidencePackage?->only(['uuid', 'evidence_hash']),
        ];

        $attributes = [
            'team_id' => $teamId,
            'dns_steering_policy_id' => $policy->id,
            'domain' => $policy->domain,
            'decided_at' => now(),
            'previous_target' => $currentIp,
            'new_target' => $canPromote ? $targetIp : null,
            'recommendation' => $recommendation,
            'reason' => $reason,
            'evidence_hash' => $evidencePackage?->evidence_hash ?: hash('sha256', $this->canonicalJson($evidence)),
            'evidence' => $evidence,
        ];

        if (Schema::hasColumn('simple_l1_failover_decisions', 'simple_l1_evidence_package_id')) {
            $attributes['simple_l1_evidence_package_id'] = $evidencePackage?->id;
        }

        return SimpleL1FailoverDecision::create($attributes);
    }

    private function recordDecisionEvidenceLink(int $teamId, ?SimpleL1FailoverDecision $decision, ?SimpleL1EvidencePackage $evidencePackage): ?SimpleL1DecisionEvidenceLink
    {
        if (
            ! $decision
            || ! $evidencePackage
            || ! Schema::hasTable('simple_l1_decision_evidence_links')
        ) {
            return null;
        }

        return SimpleL1DecisionEvidenceLink::create([
            'team_id' => $teamId,
            'simple_l1_failover_decision_id' => $decision->id,
            'simple_l1_evidence_package_id' => $evidencePackage->id,
            'link_type' => SimpleL1DecisionEvidenceLink::TYPE_PRIMARY,
            'metadata' => [
                'schema' => 'simple_l1.decision_evidence_link.v1',
            ],
            'linked_at' => now(),
        ]);
    }

    private function recordControlAction(int $teamId, DnsSteeringPolicy $policy, ?SimpleL1FailoverDecision $decision, array $plan, array $outcome): ?SimpleL1ControlAction
    {
        if (
            ! $decision
            || ! Schema::hasTable('simple_l1_control_actions')
        ) {
            return null;
        }

        return SimpleL1ControlAction::create([
            'team_id' => $teamId,
            'simple_l1_failover_decision_id' => $decision->id,
            'domain' => $policy->domain,
            'action_type' => SimpleL1ControlAction::TYPE_DNS_STEERING_APPLY,
            'adapter' => SimpleL1ControlAction::ADAPTER_CLOUDFLARE_DNS,
            'status' => SimpleL1ControlAction::STATUS_SUCCEEDED,
            'request' => [
                'policy_uuid' => $policy->uuid,
                'record_name' => data_get($plan, 'record_name'),
                'actions' => data_get($plan, 'actions', []),
            ],
            'outcome' => $outcome,
            'metadata' => [
                'schema' => 'simple_l1.control_action.v1',
            ],
            'executed_at' => now(),
        ]);
    }

    private function recommendation(?string $currentIp, ?bool $currentHealthy, ?string $targetIp, bool $canPromote): string
    {
        if ($canPromote) {
            return SimpleL1FailoverDecision::RECOMMENDATION_PROMOTE;
        }

        if ($currentHealthy === false && ! filled($targetIp)) {
            return SimpleL1FailoverDecision::RECOMMENDATION_NO_TARGET;
        }

        return SimpleL1FailoverDecision::RECOMMENDATION_NO_CHANGE;
    }

    private function canonicalJson(array $evidence): string
    {
        return json_encode($this->sortKeys($evidence), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
    }
}
