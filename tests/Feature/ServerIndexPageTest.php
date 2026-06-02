<?php

use App\Models\InstanceSettings;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Server;
use App\Models\SimpleL1ControlAction;
use App\Models\SimpleL1EvidencePackage;
use App\Models\SimpleL1FailoverDecision;
use App\Models\SimpleL1NodeObservation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

test('servers index renders the sovereign topology and sl1 federation layout', function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    Server::factory()->create([
        'team_id' => $team->id,
        'name' => 'simple-l1-primary',
        'ip' => '203.0.113.10',
    ]);
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
    SimpleL1NodeObservation::create([
        'team_id' => $team->id,
        'domain' => 'simplel1.online',
        'observed_at' => now(),
        'observer_node' => 'test-observer',
        'target_node' => 'backup',
        'target_ip' => '203.0.113.11',
        'status' => 'healthy',
        'health_source' => 'http_health',
        'health_url' => 'http://203.0.113.11/healthcheck',
    ]);
    $package = SimpleL1EvidencePackage::create([
        'team_id' => $team->id,
        'domain' => 'simplel1.online',
        'package_type' => SimpleL1EvidencePackage::TYPE_FAILOVER_ELECTION,
        'evidence_hash' => str_repeat('a', 64),
        'observations' => [['target_ip' => '203.0.113.11', 'status' => 'healthy']],
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
        'evidence_hash' => str_repeat('a', 64),
        'evidence' => ['test' => true],
        'applied_at' => now(),
    ]);
    SimpleL1ControlAction::create([
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

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Http::fake([
        'simplel1.online/api/sl1/network/join-requests*' => Http::response([
            'join_requests' => [],
            'namespace_artifacts' => [],
        ]),
    ]);

    $this->get(route('server.index'))
        ->assertOk()
        ->assertSee('L1 NETWORK TOPOLOGY')
        ->assertSee('Sovereign Authority Mesh')
        ->assertSee('Local Runtime')
        ->assertSee('Admitted SL1 Peers')
        ->assertSee('Bridge-Visible Candidates')
        ->assertSee('Simple L1 Failover')
        ->assertSee('Current Target')
        ->assertSee('203.0.113.11')
        ->assertSee('PROMOTE')
        ->assertSee('active_unhealthy_promotable')
        ->assertSee('Control Action: dns_steering_apply')
        ->assertSee('cloudflare_dns')
        ->assertSee($package->uuid)
        ->assertSee('simple-l1-primary');
});
