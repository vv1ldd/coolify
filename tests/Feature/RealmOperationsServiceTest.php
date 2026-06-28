<?php

use App\Livewire\RealmOperations\Index as RealmOperationsIndex;
use App\Models\InstanceSettings;
use App\Models\SimpleL1EvidencePackage;
use App\Models\SimpleL1NodeObservation;
use App\Models\Team;
use App\Models\User;
use App\Services\Realm\RealmOperationsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    Http::preventStrayRequests();

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
});

test('realm operations snapshot uses conservative unknown defaults without evidence', function () {
    config([
        'sovereign.realm_operations.runtime_image' => 'ghcr.io/vv1ldd/simple-l1:latest',
        'sovereign.realm_operations.runtime_status_urls' => [],
    ]);

    $snapshot = app(RealmOperationsService::class)->snapshot($this->team->id);

    expect($snapshot['artifact']['image_ref'])->toBe('ghcr.io/vv1ldd/simple-l1:latest')
        ->and($snapshot['artifact']['image_digest'])->toBe('UNKNOWN')
        ->and($snapshot['protocol']['package_fingerprint'])->toBe('UNKNOWN')
        ->and($snapshot['protocol']['distribution_digest'])->toBe('UNKNOWN')
        ->and($snapshot['runtime']['history_head'])->toBe('UNKNOWN')
        ->and($snapshot['runtime']['state_root'])->toBe('UNKNOWN')
        ->and($snapshot['runtime']['reachable'])->toBeFalse()
        ->and($snapshot['verification']['semantic_health'])->toBe('UNKNOWN')
        ->and($snapshot['verification']['shadow_verify'])->toBe('UNKNOWN')
        ->and($snapshot['verification']['conformance'])->toBe('UNKNOWN')
        ->and($snapshot['process_health']['status'])->toBe('UNKNOWN');
});

test('realm operations snapshot aggregates sealed evidence and runtime probe', function () {
    config([
        'sovereign.realm_operations.runtime_status_urls' => ['http://runtime.test'],
    ]);

    Http::fake([
        'http://runtime.test/api/sl1e/runtime/status' => Http::response([
            'identity_realm' => [
                'state_root' => 'root-from-runtime',
                'event_count' => 5,
            ],
            'history_head' => 'head-from-runtime',
        ], 200),
        'http://runtime.test/api/sl1e/runtime/verification/shadow' => Http::response([
            'verifier' => 'rust-shadow',
            'status' => 'OK',
            'semantic_health' => 'OK',
            'checked_at' => '2026-06-27T00:02:00.000Z',
            'observed_state_root' => 'root-from-runtime',
            'observed_history_head' => 'head-from-runtime',
        ], 200),
    ]);

    SimpleL1EvidencePackage::create([
        'team_id' => $this->team->id,
        'domain' => 'simplel1.online',
        'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
        'evidence_hash' => str_repeat('e', 64),
        'observations' => [
            ['shadow_verification' => 'OK'],
        ],
        'metadata' => [
            'realm' => [
                'package_fingerprint' => 'realm-v1.0',
                'distribution_digest' => 'sha256:abc123',
                'image_digest' => 'sha256:def456',
                'last_transition' => 'ACCOUNT_PROVENANCE_ADMISSION',
                'semantic_health' => 'OK',
                'conformance' => 'PASS',
            ],
        ],
        'sealed_at' => now(),
    ]);

    SimpleL1NodeObservation::create([
        'team_id' => $this->team->id,
        'domain' => 'simplel1.online',
        'observed_at' => now(),
        'observer_node' => 'panel',
        'target_node' => 'lena',
        'target_ip' => '203.0.113.10',
        'status' => 'healthy',
    ]);

    $snapshot = app(RealmOperationsService::class)->snapshot($this->team->id);

    expect($snapshot['protocol']['package_fingerprint'])->toBe('realm-v1.0')
        ->and($snapshot['protocol']['distribution_digest'])->toBe('sha256:abc123')
        ->and($snapshot['artifact']['image_digest'])->toBe('sha256:def456')
        ->and($snapshot['runtime']['state_root'])->toBe('root-from-runtime')
        ->and($snapshot['runtime']['history_head'])->toBe('head-from-runtime')
        ->and($snapshot['runtime']['last_transition'])->toBe('ACCOUNT_PROVENANCE_ADMISSION')
        ->and($snapshot['runtime']['reachable'])->toBeTrue()
        ->and($snapshot['verification']['semantic_health'])->toBe('OK')
        ->and($snapshot['verification']['shadow_verify'])->toBe('OK')
        ->and($snapshot['verification']['conformance'])->toBe('OK')
        ->and($snapshot['process_health']['status'])->toBe('OK')
        ->and($snapshot['evidence_refs']['package_count'])->toBe(1);
});

test('realm operations snapshot reads runtime causality from identity realm status', function () {
    config([
        'sovereign.realm_operations.runtime_status_urls' => ['http://runtime.test'],
    ]);

    Http::fake([
        'http://runtime.test/api/sl1e/runtime/status' => Http::response([
            'identity_realm' => [
                'state_root' => 'root-from-runtime',
                'event_count' => 5,
                'history_head' => 'event-log-head',
                'history_head_kind' => 'event_log_hash',
                'last_transition' => [
                    'type' => 'ACCOUNT_PROVENANCE_ADMISSION',
                    'id' => 'provadm_test',
                    'timestamp' => '2026-06-27T00:01:00.000Z',
                ],
            ],
        ], 200),
        'http://runtime.test/api/sl1e/runtime/verification/shadow' => Http::response([
            'verifier' => 'rust-shadow',
            'status' => 'UNSUPPORTED',
            'semantic_health' => 'UNKNOWN',
            'checked_at' => '2026-06-27T00:02:00.000Z',
            'raw_event_count' => 5,
            'canonical_event_count' => 0,
            'reason' => 'UNSUPPORTED_HISTORY_CONTRACT:NO_CANONICAL_REALM_EVENTS',
            'observed_state_root' => 'root-from-runtime',
            'observed_history_head' => 'event-log-head',
        ], 200),
    ]);

    $snapshot = app(RealmOperationsService::class)->snapshot($this->team->id);

    expect($snapshot['runtime']['history_head'])->toBe('event-log-head')
        ->and($snapshot['runtime']['history_head_kind'])->toBe('event_log_hash')
        ->and($snapshot['runtime']['last_transition'])->toBe('ACCOUNT_PROVENANCE_ADMISSION')
        ->and($snapshot['runtime']['state_root'])->toBe('root-from-runtime')
        ->and($snapshot['runtime']['event_count'])->toBe(5)
        ->and($snapshot['runtime']['reachable'])->toBeTrue()
        ->and($snapshot['verification']['semantic_health'])->toBe('UNKNOWN')
        ->and($snapshot['verification']['shadow_verify'])->toBe('UNKNOWN')
        ->and($snapshot['verification']['verifier'])->toBe('rust-shadow')
        ->and($snapshot['verification']['reason'])->toBe('UNSUPPORTED_HISTORY_CONTRACT:NO_CANONICAL_REALM_EVENTS')
        ->and($snapshot['verification']['raw_event_count'])->toBe(5)
        ->and($snapshot['verification']['canonical_event_count'])->toBe(0);
});

test('realm operations page renders read-only evidence surface', function () {
    config([
        'sovereign.realm_operations.runtime_status_urls' => [],
    ]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Livewire::test(RealmOperationsIndex::class)
        ->assertOk()
        ->assertSee('Realm Operations')
        ->assertSee('Observe', false)
        ->assertSee('Aggregate', false)
        ->assertSee('Display', false)
        ->assertSee('Semantic health')
        ->assertSee('Process Health (Not Semantic Health)');
});

test('realm operations status normalization is conservative', function () {
    $service = app(RealmOperationsService::class);

    expect($service->normalizeStatus(null))->toBe('UNKNOWN')
        ->and($service->normalizeStatus('OK'))->toBe('OK')
        ->and($service->normalizeStatus('DIVERGED'))->toBe('FAIL')
        ->and($service->normalizeConformanceStatus('PASS'))->toBe('OK')
        ->and($service->normalizeConformanceStatus('FAILED'))->toBe('FAIL');
});
