<?php

use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\SimpleL1ControlAction;
use App\Models\SimpleL1DecisionEvidenceLink;
use App\Models\SimpleL1EvidencePackage;
use App\Models\SimpleL1FailoverDecision;
use App\Models\SimpleL1NodeObservation;
use App\Models\Team;
use App\Services\Dns\DnsZoneService;
use App\Services\SimpleL1\SimpleL1PanelFailoverService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    Http::preventStrayRequests();
});

test('simple l1 panel failover promotes backup only when current dns target is unhealthy', function () {
    $team = Team::factory()->create();
    $zone = DnsZone::create([
        'team_id' => $team->id,
        'provider' => 'cloudflare',
        'name' => 'simplel1.online',
        'provider_zone_id' => 'zone-simple-l1',
        'api_token' => 'cloudflare-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'simplel1.online',
        'content' => '203.0.113.10',
        'ttl' => 60,
        'proxied' => false,
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'simplel1.online',
        'record_name' => 'simplel1.online',
        'resource_type' => 'simple_l1',
        'resource_uuid' => 'embedded-runtime',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'primary', 'ip' => '203.0.113.10', 'priority' => 1, 'health_url' => 'http://203.0.113.10/healthcheck', 'health_host' => 'simplel1.online'],
            ['name' => 'backup', 'ip' => '203.0.113.11', 'priority' => 2, 'health_url' => 'http://203.0.113.11/healthcheck', 'health_host' => 'simplel1.online'],
        ],
        'metadata' => [
            'ttl' => 60,
            'health_timeout' => 1,
        ],
    ]);

    Http::fake([
        'http://203.0.113.10/healthcheck' => Http::response([], 500),
        'http://203.0.113.11/healthcheck' => Http::response(['status' => 'ok'], 200),
    ]);

    $fakeDnsZones = new class extends DnsZoneService
    {
        public array $upserted = [];

        public function upsertManagedRecord(DnsZone $zone, array $data, int $teamId): DnsRecord
        {
            $this->upserted[] = $data;

            return DnsRecord::updateOrCreate(
                [
                    'dns_zone_id' => $zone->id,
                    'type' => $data['type'],
                    'name' => $data['name'],
                ],
                [
                    'content' => $data['content'],
                    'ttl' => $data['ttl'],
                    'proxied' => $data['proxied'],
                    'metadata' => data_get($data, 'metadata', []),
                ],
            );
        }
    };
    app()->instance(DnsZoneService::class, $fakeDnsZones);

    $result = app(SimpleL1PanelFailoverService::class)->evaluate($team->id, 'simplel1.online', apply: true);

    expect($result['status'])->toBe('promoted')
        ->and($result['current_ip'])->toBe('203.0.113.10')
        ->and($result['current_healthy'])->toBeFalse()
        ->and($result['target_ip'])->toBe('203.0.113.11')
        ->and(data_get($fakeDnsZones->upserted, '0.content'))->toBe('203.0.113.11')
        ->and(DnsRecord::where('name', 'simplel1.online')->first()->content)->toBe('203.0.113.11');

    expect(SimpleL1NodeObservation::count())->toBe(2);
    expect(SimpleL1EvidencePackage::count())->toBe(1);
    expect(SimpleL1DecisionEvidenceLink::count())->toBe(1);
    expect(SimpleL1FailoverDecision::count())->toBe(1);
    expect(SimpleL1ControlAction::count())->toBe(1);

    $decision = SimpleL1FailoverDecision::first();
    $package = SimpleL1EvidencePackage::first();
    $link = SimpleL1DecisionEvidenceLink::first();
    $controlAction = SimpleL1ControlAction::first();
    expect($decision->recommendation)->toBe(SimpleL1FailoverDecision::RECOMMENDATION_PROMOTE)
        ->and($decision->previous_target)->toBe('203.0.113.10')
        ->and($decision->new_target)->toBe('203.0.113.11')
        ->and($decision->applied_at)->not->toBeNull()
        ->and($decision->evidence_hash)->toBe($package->evidence_hash)
        ->and($decision->simple_l1_evidence_package_id)->toBe($package->id)
        ->and($link->simple_l1_failover_decision_id)->toBe($decision->id)
        ->and($link->simple_l1_evidence_package_id)->toBe($package->id)
        ->and($link->link_type)->toBe(SimpleL1DecisionEvidenceLink::TYPE_PRIMARY)
        ->and($controlAction->simple_l1_failover_decision_id)->toBe($decision->id)
        ->and($controlAction->adapter)->toBe(SimpleL1ControlAction::ADAPTER_CLOUDFLARE_DNS)
        ->and($controlAction->status)->toBe(SimpleL1ControlAction::STATUS_SUCCEEDED)
        ->and(data_get($controlAction->outcome, 'applied.0.content'))->toBe('203.0.113.11')
        ->and(data_get($decision->evidence, 'recommendation_snapshot.recommendation'))->toBe(SimpleL1FailoverDecision::RECOMMENDATION_PROMOTE)
        ->and(data_get($package->metadata, 'current_target'))->toBe('203.0.113.10')
        ->and(data_get($package->metadata, 'schema'))->toBe('simple_l1.failover.evidence_package.v1');
});

test('simple l1 panel failover does not fail back while current dns target is healthy', function () {
    $team = Team::factory()->create();
    $zone = DnsZone::create([
        'team_id' => $team->id,
        'provider' => 'cloudflare',
        'name' => 'simplel1.online',
        'provider_zone_id' => 'zone-simple-l1',
        'api_token' => 'cloudflare-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'simplel1.online',
        'content' => '203.0.113.11',
        'ttl' => 60,
        'proxied' => false,
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'simplel1.online',
        'record_name' => 'simplel1.online',
        'resource_type' => 'simple_l1',
        'resource_uuid' => 'embedded-runtime',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'primary', 'ip' => '203.0.113.10', 'priority' => 1, 'health_url' => 'http://203.0.113.10/healthcheck', 'health_host' => 'simplel1.online'],
            ['name' => 'backup', 'ip' => '203.0.113.11', 'priority' => 2, 'health_url' => 'http://203.0.113.11/healthcheck', 'health_host' => 'simplel1.online'],
        ],
        'metadata' => [
            'ttl' => 60,
            'health_timeout' => 1,
        ],
    ]);

    Http::fake([
        'http://203.0.113.10/healthcheck' => Http::response(['status' => 'ok'], 200),
        'http://203.0.113.11/healthcheck' => Http::response(['status' => 'ok'], 200),
    ]);

    $result = app(SimpleL1PanelFailoverService::class)->evaluate($team->id, 'simplel1.online', apply: true);

    expect($result['status'])->toBe('active_healthy')
        ->and($result['can_promote'])->toBeFalse()
        ->and($result['applied'])->toBeFalse()
        ->and(DnsRecord::where('name', 'simplel1.online')->first()->content)->toBe('203.0.113.11');

    expect(SimpleL1NodeObservation::count())->toBe(2);
    expect(SimpleL1EvidencePackage::count())->toBe(1);
    expect(SimpleL1DecisionEvidenceLink::count())->toBe(1);
    expect(SimpleL1FailoverDecision::count())->toBe(1);
    expect(SimpleL1ControlAction::count())->toBe(0);

    $decision = SimpleL1FailoverDecision::first();
    $package = SimpleL1EvidencePackage::first();
    $link = SimpleL1DecisionEvidenceLink::first();
    expect($decision->recommendation)->toBe(SimpleL1FailoverDecision::RECOMMENDATION_NO_CHANGE)
        ->and($decision->previous_target)->toBe('203.0.113.11')
        ->and($decision->new_target)->toBeNull()
        ->and($decision->applied_at)->toBeNull()
        ->and($decision->simple_l1_evidence_package_id)->toBe($package->id)
        ->and($link->simple_l1_failover_decision_id)->toBe($decision->id)
        ->and($link->simple_l1_evidence_package_id)->toBe($package->id)
        ->and(data_get($decision->evidence, 'recommendation_snapshot.recommendation'))->toBe(SimpleL1FailoverDecision::RECOMMENDATION_NO_CHANGE)
        ->and(data_get($package->metadata, 'current_target'))->toBe('203.0.113.11');
});

test('evidence package is immutable while decision authority state can change', function () {
    $team = Team::factory()->create();
    $package = SimpleL1EvidencePackage::create([
        'team_id' => $team->id,
        'domain' => 'simplel1.online',
        'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
        'evidence_hash' => str_repeat('b', 64),
        'observations' => [['target_ip' => '203.0.113.10', 'status' => 'unhealthy']],
        'metadata' => ['schema' => 'simple_l1.failover.evidence_package.v1'],
        'sealed_at' => now(),
    ]);
    $decision = SimpleL1FailoverDecision::create([
        'team_id' => $team->id,
        'simple_l1_evidence_package_id' => $package->id,
        'domain' => 'simplel1.online',
        'decided_at' => now(),
        'previous_target' => '203.0.113.10',
        'new_target' => '203.0.113.11',
        'recommendation' => SimpleL1FailoverDecision::RECOMMENDATION_PROMOTE,
        'reason' => 'active_unhealthy_promotable',
        'evidence_hash' => $package->evidence_hash,
        'evidence' => ['evidence_package' => $package->only(['uuid', 'evidence_hash'])],
    ]);
    $link = SimpleL1DecisionEvidenceLink::create([
        'team_id' => $team->id,
        'simple_l1_failover_decision_id' => $decision->id,
        'simple_l1_evidence_package_id' => $package->id,
        'link_type' => SimpleL1DecisionEvidenceLink::TYPE_PRIMARY,
        'metadata' => ['schema' => 'simple_l1.decision_evidence_link.v1'],
        'linked_at' => now(),
    ]);
    $controlAction = SimpleL1ControlAction::create([
        'team_id' => $team->id,
        'simple_l1_failover_decision_id' => $decision->id,
        'domain' => 'simplel1.online',
        'action_type' => SimpleL1ControlAction::TYPE_DNS_STEERING_APPLY,
        'adapter' => SimpleL1ControlAction::ADAPTER_CLOUDFLARE_DNS,
        'status' => SimpleL1ControlAction::STATUS_SUCCEEDED,
        'request' => ['actions' => []],
        'outcome' => ['ok' => true],
        'metadata' => ['schema' => 'simple_l1.control_action.v1'],
        'executed_at' => now(),
    ]);

    expect(fn () => $package->update(['metadata' => ['schema' => 'changed']]))
        ->toThrow(\LogicException::class, 'Simple L1 evidence packages are immutable after sealing.');

    expect(fn () => $link->update(['link_type' => 'secondary']))
        ->toThrow(\LogicException::class, 'Simple L1 decision evidence links are immutable.');

    expect(fn () => $controlAction->update(['status' => SimpleL1ControlAction::STATUS_FAILED]))
        ->toThrow(\LogicException::class, 'Simple L1 control actions are append-only execution records.');

    $decision->update([
        'applied_result' => ['provider' => 'cloudflare', 'ok' => true],
        'applied_by' => 'test-operator',
        'applied_at' => now(),
    ]);

    expect($decision->refresh()->applied_result)->toBe(['provider' => 'cloudflare', 'ok' => true])
        ->and($package->refresh()->metadata)->toBe(['schema' => 'simple_l1.failover.evidence_package.v1']);
});

test('decision evidence causality graph is append only', function () {
    $team = Team::factory()->create();
    $healthPackage = SimpleL1EvidencePackage::create([
        'team_id' => $team->id,
        'domain' => 'simplel1.online',
        'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
        'evidence_hash' => str_repeat('c', 64),
        'observations' => [['target_ip' => '203.0.113.10', 'status' => 'unhealthy']],
        'metadata' => ['schema' => 'simple_l1.failover.evidence_package.v1', 'source' => 'health'],
        'sealed_at' => now(),
    ]);
    $latencyPackage = SimpleL1EvidencePackage::create([
        'team_id' => $team->id,
        'domain' => 'simplel1.online',
        'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
        'evidence_hash' => str_repeat('d', 64),
        'observations' => [['target_ip' => '203.0.113.10', 'latency_ms' => 3500]],
        'metadata' => ['schema' => 'simple_l1.failover.evidence_package.v1', 'source' => 'latency'],
        'sealed_at' => now(),
    ]);
    $decision = SimpleL1FailoverDecision::create([
        'team_id' => $team->id,
        'simple_l1_evidence_package_id' => $healthPackage->id,
        'domain' => 'simplel1.online',
        'decided_at' => now(),
        'previous_target' => '203.0.113.10',
        'new_target' => '203.0.113.11',
        'recommendation' => SimpleL1FailoverDecision::RECOMMENDATION_PROMOTE,
        'reason' => 'active_unhealthy_promotable',
        'evidence_hash' => $healthPackage->evidence_hash,
        'evidence' => ['evidence_package' => $healthPackage->only(['uuid', 'evidence_hash'])],
    ]);

    $primary = SimpleL1DecisionEvidenceLink::create([
        'team_id' => $team->id,
        'simple_l1_failover_decision_id' => $decision->id,
        'simple_l1_evidence_package_id' => $healthPackage->id,
        'link_type' => SimpleL1DecisionEvidenceLink::TYPE_HEALTH,
        'metadata' => ['schema' => 'simple_l1.decision_evidence_link.v1'],
        'linked_at' => now(),
    ]);
    $supporting = SimpleL1DecisionEvidenceLink::create([
        'team_id' => $team->id,
        'simple_l1_failover_decision_id' => $decision->id,
        'simple_l1_evidence_package_id' => $latencyPackage->id,
        'link_type' => SimpleL1DecisionEvidenceLink::TYPE_LATENCY,
        'metadata' => ['schema' => 'simple_l1.decision_evidence_link.v1'],
        'linked_at' => now(),
    ]);

    expect($decision->evidenceLinks()->count())->toBe(2)
        ->and($decision->evidenceLinks()->orderBy('id')->pluck('simple_l1_evidence_package_id')->all())->toBe([$healthPackage->id, $latencyPackage->id])
        ->and($primary->refresh()->simple_l1_evidence_package_id)->toBe($healthPackage->id)
        ->and($supporting->causalityStatement())->toContain('depends_on')
        ->and(method_exists($healthPackage, 'decisions'))->toBeFalse();
});
