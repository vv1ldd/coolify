<?php

namespace App\Services\ResourceContinuity;

use App\Models\EdgeProjection;
use App\Models\ResourceArbitrationDecision;
use App\Models\ResourceObservation;
use App\Models\ResourceReconciliationAssessment;
use App\Models\ResourceRoutingPolicy;
use App\Services\EdgeControl\EdgeProjectionService;

class ResourceContinuityService
{
    public function __construct(
        private readonly EdgeProjectionService $projections,
    ) {}

    public function project(ResourceRoutingPolicy $policy): ?EdgeProjection
    {
        if ($policy->routing_layer === ResourceRoutingPolicy::LAYER_DNS) {
            return null;
        }

        return $this->projections->generateEdgeRuntimeProjection([
            'domain' => $policy->domain,
            'uses' => [
                [
                    'resource_type' => $policy->resource_type,
                    'resource_uuid' => $policy->resource_uuid,
                    'resource_name' => $policy->resource_type,
                    'project_name' => data_get($policy->metadata, 'project_name'),
                    'environment_name' => data_get($policy->metadata, 'environment_name'),
                ],
            ],
            'edge_policy' => data_get($policy->metadata, 'edge_policy', ['mode' => 'normal']),
            'dns_steering' => null,
        ], metadata: [
            'team_id' => $policy->team_id,
            'intent_uuid' => $policy->uuid,
            'intent_version' => (int) data_get($policy->metadata, 'intent_version', 1),
            'resource_type' => $policy->resource_type,
            'resource_uuid' => $policy->resource_uuid,
            'routing_layer' => $policy->routing_layer,
        ]);
    }

    public function observe(ResourceRoutingPolicy $policy, array $backend): ResourceObservation
    {
        $healthy = (bool) data_get($backend, 'healthy', true);

        return ResourceObservation::create([
            'team_id' => $policy->team_id,
            'resource_routing_policy_id' => $policy->id,
            'resource_type' => $policy->resource_type,
            'resource_uuid' => $policy->resource_uuid,
            'backend' => data_get($backend, 'backend') ?: data_get($backend, 'name') ?: data_get($backend, 'ip'),
            'status' => $healthy ? ResourceObservation::STATUS_HEALTHY : ResourceObservation::STATUS_UNHEALTHY,
            'latency_ms' => data_get($backend, 'latency_ms'),
            'confidence' => (int) data_get($backend, 'confidence', 100),
            'evidence' => $backend,
            'observed_at' => now(),
        ]);
    }

    public function assess(ResourceRoutingPolicy $policy): ResourceReconciliationAssessment
    {
        $observations = $policy->observations()
            ->latest('observed_at')
            ->limit(20)
            ->get();
        $healthyBackends = $observations
            ->where('status', ResourceObservation::STATUS_HEALTHY)
            ->pluck('backend')
            ->filter()
            ->unique()
            ->values();
        $unhealthyBackends = $observations
            ->where('status', ResourceObservation::STATUS_UNHEALTHY)
            ->pluck('backend')
            ->filter()
            ->unique()
            ->values();
        $currentBackend = data_get($policy->metadata, 'current_backend');
        $targetBackend = $healthyBackends->first(fn (string $backend): bool => $backend !== $currentBackend);
        $currentUnhealthy = filled($currentBackend) && $unhealthyBackends->contains($currentBackend);
        $conflicts = $currentUnhealthy && blank($targetBackend)
            ? ['current_unhealthy_no_healthy_target']
            : [];
        $assessment = [
            'resource_type' => $policy->resource_type,
            'resource_uuid' => $policy->resource_uuid,
            'routing_layer' => $policy->routing_layer,
            'current_backend' => $currentBackend,
            'target_backend' => $targetBackend,
            'current_unhealthy' => $currentUnhealthy,
            'healthy_backends' => $healthyBackends->all(),
            'unhealthy_backends' => $unhealthyBackends->all(),
            'conflicts' => $conflicts,
        ];

        return ResourceReconciliationAssessment::create([
            'team_id' => $policy->team_id,
            'resource_routing_policy_id' => $policy->id,
            'resource_type' => $policy->resource_type,
            'resource_uuid' => $policy->resource_uuid,
            'scope' => 'resource_routing',
            'assessment_hash' => $this->hashPayload($assessment),
            'observation_refs' => $observations
                ->map(fn (ResourceObservation $observation): array => [
                    'uuid' => $observation->uuid,
                    'backend' => $observation->backend,
                    'status' => $observation->status,
                    'observed_at' => $observation->observed_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'conflicts' => $conflicts,
            'candidates' => [
                'healthy_backends' => $healthyBackends->all(),
                'unhealthy_backends' => $unhealthyBackends->all(),
            ],
            'assessment' => $assessment,
            'severity' => count($conflicts) > 0 ? 80 : ($currentUnhealthy ? 50 : 0),
            'assessed_at' => now(),
        ]);
    }

    public function arbitrate(ResourceRoutingPolicy $policy, ?EdgeProjection $projection = null): ResourceArbitrationDecision
    {
        $assessment = $this->assess($policy);
        $assessmentPayload = $assessment->assessment;
        $decision = ResourceArbitrationDecision::DECISION_NO_CHANGE;
        $reason = 'current_backend_not_proven_unhealthy';

        if (data_get($assessmentPayload, 'current_unhealthy') && filled(data_get($assessmentPayload, 'target_backend'))) {
            $decision = ResourceArbitrationDecision::DECISION_SWITCH_BACKEND;
            $reason = 'healthy_target_available';
        }

        if (data_get($assessmentPayload, 'current_unhealthy') && blank(data_get($assessmentPayload, 'target_backend'))) {
            $decision = ResourceArbitrationDecision::DECISION_UNRESOLVED;
            $reason = 'unresolved_conflict_no_healthy_target';
        }
        $authorityScope = (string) data_get($policy->metadata, 'authority_scope', 'resource_routing');
        $authorityActor = (string) data_get($policy->metadata, 'authority_actor', 'resource-continuity-service');
        $authorityBasis = (string) data_get($policy->metadata, 'authority_basis', 'system_resource_routing_policy');
        $rationale = [
            'assessment_hash' => $assessment->assessment_hash,
            'selected_decision' => $decision,
            'selected_reason' => $reason,
            'authority_scope' => $authorityScope,
            'authority_basis' => $authorityBasis,
            'candidate_target' => data_get($assessmentPayload, 'target_backend'),
            'current_backend' => data_get($assessmentPayload, 'current_backend'),
        ];
        $previousDecision = ResourceArbitrationDecision::query()
            ->where('resource_routing_policy_id', $policy->id)
            ->latest('decided_at')
            ->first();
        $decisionPayload = [
            'assessment_hash' => $assessment->assessment_hash,
            'authority_scope' => $authorityScope,
            'authority_actor' => $authorityActor,
            'authority_basis' => $authorityBasis,
            'decision' => $decision,
            'reason' => $reason,
            'rationale' => $rationale,
            'supersedes_decision_uuid' => $previousDecision?->uuid,
        ];

        return ResourceArbitrationDecision::create([
            'team_id' => $policy->team_id,
            'resource_routing_policy_id' => $policy->id,
            'resource_reconciliation_assessment_id' => $assessment->id,
            'edge_projection_id' => $projection?->id,
            'scope' => 'resource_routing',
            'authority_scope' => $authorityScope,
            'authority_actor' => $authorityActor,
            'authority_basis' => $authorityBasis,
            'supersedes_decision_id' => $previousDecision?->id,
            'decision' => $decision,
            'decision_hash' => $this->hashPayload($decisionPayload),
            'reason' => $reason,
            'assessment' => $assessmentPayload,
            'rationale' => $rationale,
            'metadata' => [
                'schema' => 'resource.continuity.arbitration.v1',
                'conflict_policy' => 'append_only_no_rollback',
                'assessment_hash' => $assessment->assessment_hash,
            ],
            'decided_at' => now(),
        ]);
    }

    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($this->sortKeys($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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
