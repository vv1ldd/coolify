<?php

use App\Models\ControlPlanePeer;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;
use App\Models\EdgeProjectionVersion;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Services\EdgeControl\Adapters\CloudflareDnsControlAdapter;
use App\Services\EdgeControl\Adapters\ProbeHealthObservationAdapter;
use App\Services\EdgeControl\Adapters\TraefikEdgeRuntimeAdapter;
use App\Services\EdgeControl\EdgeProjectionScheduler;
use App\Services\EdgeControl\EdgeProjectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
});

test('dns record projections are versioned and cloudflare adapter records execution', function () {
    $team = Team::factory()->create();
    $zone = DnsZone::create([
        'team_id' => $team->id,
        'provider' => DnsZone::PROVIDER_CLOUDFLARE,
        'name' => 'example.com',
        'provider_zone_id' => 'zone-edge',
        'api_token' => 'cloudflare-token',
    ]);
    $record = DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
        'ttl' => 1,
        'proxied' => true,
    ]);

    $projection = app(EdgeProjectionService::class)->generateDnsRecordProjection($record);

    expect($projection)->not()->toBeNull()
        ->and($projection->adapter)->toBe(EdgeProjection::ADAPTER_CLOUDFLARE)
        ->and($projection->projection_type)->toBe(EdgeProjection::TYPE_DNS_RECORD)
        ->and($projection->intent_hash)->toHaveLength(64)
        ->and($projection->projection_hash)->toHaveLength(64)
        ->and($projection->projection_version)->toBe(1)
        ->and(EdgeProjectionVersion::count())->toBe(1);

    app(CloudflareDnsControlAdapter::class)->apply($projection);

    expect(EdgeControlAction::count())->toBe(1)
        ->and(EdgeControlAction::first()->adapter)->toBe(EdgeProjection::ADAPTER_CLOUDFLARE)
        ->and($projection->refresh()->applied_projection_hash)->toBe($projection->projection_hash);

    $record->update(['content' => '203.0.113.11']);
    $updatedProjection = app(EdgeProjectionService::class)->generateDnsRecordProjection($record->refresh());

    expect($updatedProjection->projection_version)->toBe(2)
        ->and($updatedProjection->intent_drift_status)->toBe(EdgeProjection::INTENT_DRIFT_PENDING_APPLY)
        ->and($updatedProjection->conflict_policy)->toBe(EdgeProjection::CONFLICT_APPEND_ONLY_NO_ROLLBACK)
        ->and(EdgeProjectionVersion::count())->toBe(2)
        ->and(fn () => EdgeProjectionVersion::first()->update(['metadata' => ['changed' => true]]))
        ->toThrow(\LogicException::class, 'Edge projection versions are immutable.');
});

test('projection scheduling is constrained by node capabilities', function () {
    $team = Team::factory()->create();
    $zone = DnsZone::create([
        'team_id' => $team->id,
        'provider' => DnsZone::PROVIDER_HYBRID,
        'name' => 'example.com',
        'provider_zone_id' => 'zone-edge',
        'api_token' => 'cloudflare-token',
        'metadata' => [
            'adapter_mode' => DnsZone::PROVIDER_HYBRID,
            'authoritative' => true,
            'cloudflare_sync' => true,
        ],
    ]);

    $projection = app(EdgeProjectionService::class)->generateAuthoritativeDnsProjection($zone);
    $scheduler = app(EdgeProjectionScheduler::class);

    expect($scheduler->readiness($projection)['schedulable'])->toBeFalse();

    $scheduler->markNotScheduledIfMissingCapabilities($projection);

    expect($projection->refresh()->status)->toBe(EdgeProjection::STATUS_NOT_SCHEDULED)
        ->and(data_get($projection->metadata, 'schedule_reason'))->toBe('no_node_provides_required_capabilities');

    ControlPlanePeer::create([
        'team_id' => $team->id,
        'name' => 'ns-1',
        'endpoint_url' => 'https://ns-1.example.com',
        'public_ip' => '198.51.100.10',
        'role' => ControlPlanePeer::ROLE_EDGE_AGENT,
        'status' => ControlPlanePeer::STATUS_ONLINE,
        'last_seen_at' => now(),
        'capabilities' => [ControlPlanePeer::CAPABILITY_AUTHORITATIVE_DNS],
        'metadata' => ['capability_version' => 7],
    ]);

    $ready = $scheduler->readiness($projection->refresh());
    $scheduler->markNotScheduledIfMissingCapabilities($projection->refresh());

    expect($ready['schedulable'])->toBeTrue()
        ->and($ready['capability_snapshot_hash'])->toHaveLength(64)
        ->and($ready['capability_snapshot_version'])->toBe(7)
        ->and($projection->refresh()->capability_snapshot_hash)->toHaveLength(64)
        ->and($projection->capability_snapshot_version)->toBe(7);
});

test('traefik edge runtime projection records adapter execution', function () {
    $team = Team::factory()->create();
    $projection = app(EdgeProjectionService::class)->generateEdgeRuntimeProjection([
        'domain' => 'app.example.com',
        'uses' => [
            [
                'resource_type' => 'application',
                'resource_uuid' => 'app-uuid',
                'resource_name' => 'Storefront',
                'project_name' => 'Meanly',
                'environment_name' => 'production',
            ],
        ],
        'edge_policy' => ['mode' => 'normal'],
    ], metadata: ['team_id' => $team->id]);

    expect($projection)->not()->toBeNull()
        ->and($projection->adapter)->toBe(EdgeProjection::ADAPTER_TRAEFIK)
        ->and($projection->required_capabilities)->toBe([ControlPlanePeer::CAPABILITY_EDGE_RUNTIME]);

    app(TraefikEdgeRuntimeAdapter::class)->apply($projection);

    $action = EdgeControlAction::first();
    expect($action->action_type)->toBe(EdgeControlAction::TYPE_EDGE_RUNTIME_APPLY)
        ->and($action->adapter)->toBe(EdgeProjection::ADAPTER_TRAEFIK)
        ->and($projection->refresh()->status)->toBe(EdgeProjection::STATUS_APPLIED)
        ->and(fn () => $action->update(['status' => EdgeControlAction::STATUS_FAILED]))
        ->toThrow(\LogicException::class, 'Edge control actions are append-only execution records.');
});

test('observation trust state is recorded separately from adapter execution', function () {
    $team = Team::factory()->create();
    $projection = app(EdgeProjectionService::class)->generateEdgeRuntimeProjection([
        'domain' => 'app.example.com',
        'uses' => [],
        'edge_policy' => ['mode' => 'normal'],
    ], metadata: ['team_id' => $team->id]);

    app(TraefikEdgeRuntimeAdapter::class)->apply($projection);
    $observation = app(ProbeHealthObservationAdapter::class)->observe($projection->refresh());

    expect($observation['status'])->toBe('not_probed')
        ->and($projection->refresh()->observed_state_hash)->toHaveLength(64)
        ->and($projection->observation_confidence)->toBe(0)
        ->and($projection->observation_quorum_status)->toBe(EdgeProjection::QUORUM_PENDING)
        ->and($projection->observedTruthState())->toBe('observation_drift');
});
