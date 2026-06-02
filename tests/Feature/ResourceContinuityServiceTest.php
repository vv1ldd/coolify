<?php

use App\Models\EdgeProjection;
use App\Models\InstanceSettings;
use App\Models\ResourceArbitrationDecision;
use App\Models\ResourceObservation;
use App\Models\ResourceReconciliationAssessment;
use App\Models\ResourceRoutingPolicy;
use App\Models\Team;
use App\Services\ResourceContinuity\ResourceContinuityService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
});

test('marketplace routing policy projects observes and arbitrates l7 failover', function () {
    $team = Team::factory()->create();
    $policy = ResourceRoutingPolicy::create([
        'team_id' => $team->id,
        'resource_type' => ResourceRoutingPolicy::RESOURCE_MARKETPLACE,
        'resource_uuid' => 'marketplace-main',
        'domain' => 'marketplace.example.com',
        'routing_layer' => ResourceRoutingPolicy::LAYER_L7,
        'strategy' => ResourceRoutingPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_backends' => [
            ['backend' => 'node-a', 'healthy' => false],
            ['backend' => 'node-b', 'healthy' => true],
        ],
        'metadata' => [
            'current_backend' => 'node-a',
            'project_name' => 'Meanly',
            'environment_name' => 'production',
            'authority_scope' => 'marketplace',
            'authority_actor' => 'edge-operator-1',
            'authority_basis' => 'delegated_marketplace_authority_v3',
        ],
    ]);

    $service = app(ResourceContinuityService::class);
    $projection = $service->project($policy);
    $service->observe($policy, ['backend' => 'node-a', 'healthy' => false, 'confidence' => 90]);
    $service->observe($policy, ['backend' => 'node-b', 'healthy' => true, 'confidence' => 90]);
    $decision = $service->arbitrate($policy, $projection);
    $assessment = ResourceReconciliationAssessment::first();

    expect($projection)->not()->toBeNull()
        ->and($projection->projection_type)->toBe(EdgeProjection::TYPE_EDGE_RUNTIME)
        ->and($projection->adapter)->toBe(EdgeProjection::ADAPTER_TRAEFIK)
        ->and(ResourceObservation::count())->toBe(2)
        ->and(ResourceReconciliationAssessment::count())->toBe(1)
        ->and($assessment->assessment_hash)->toHaveLength(64)
        ->and($assessment->observation_refs)->toHaveCount(2)
        ->and($decision->resource_reconciliation_assessment_id)->toBe($assessment->id)
        ->and($decision->decision_hash)->toHaveLength(64)
        ->and($decision->authority_scope)->toBe('marketplace')
        ->and($decision->authority_actor)->toBe('edge-operator-1')
        ->and($decision->authority_basis)->toBe('delegated_marketplace_authority_v3')
        ->and(data_get($decision->rationale, 'assessment_hash'))->toBe($assessment->assessment_hash)
        ->and($decision->decision)->toBe(ResourceArbitrationDecision::DECISION_SWITCH_BACKEND)
        ->and($decision->reason)->toBe('healthy_target_available')
        ->and(data_get($decision->assessment, 'current_backend'))->toBe('node-a')
        ->and(data_get($decision->assessment, 'target_backend'))->toBe('node-b');

    expect(fn () => $assessment->update(['severity' => 1]))
        ->toThrow(\LogicException::class, 'Resource reconciliation assessments are immutable.');

    $secondDecision = $service->arbitrate($policy, $projection);
    expect($secondDecision->supersedes_decision_id)->toBe($decision->id)
        ->and(fn () => $decision->update(['reason' => 'changed']))
        ->toThrow(\LogicException::class, 'Resource arbitration decisions are append-only authority records.');
});

test('unresolved resource conflict is a stable arbitration state', function () {
    $team = Team::factory()->create();
    $policy = ResourceRoutingPolicy::create([
        'team_id' => $team->id,
        'resource_type' => ResourceRoutingPolicy::RESOURCE_API,
        'resource_uuid' => 'api-main',
        'domain' => 'api.example.com',
        'routing_layer' => ResourceRoutingPolicy::LAYER_L7,
        'enabled' => true,
        'metadata' => [
            'current_backend' => 'node-a',
        ],
    ]);

    $service = app(ResourceContinuityService::class);
    $service->observe($policy, ['backend' => 'node-a', 'healthy' => false]);
    $decision = $service->arbitrate($policy);
    $assessment = ResourceReconciliationAssessment::first();

    expect($decision->decision)->toBe(ResourceArbitrationDecision::DECISION_UNRESOLVED)
        ->and($decision->reason)->toBe('unresolved_conflict_no_healthy_target')
        ->and($decision->resource_reconciliation_assessment_id)->toBe($assessment->id)
        ->and($assessment->severity)->toBe(80)
        ->and(data_get($decision->assessment, 'conflicts.0'))->toBe('current_unhealthy_no_healthy_target');
});
