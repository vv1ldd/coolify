<?php

use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\DnsSteeringPolicyService;
use App\Services\Dns\DnsZoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    Http::preventStrayRequests();
});

test('dns steering plan updates managed A record from healthy server candidate', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
        'ttl' => 60,
        'proxied' => false,
    ]);

    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '198.51.100.20',
    ]);
    $server->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $policy = DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'app.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_HEALTH_BASED,
        'enabled' => false,
        'candidate_nodes' => [
            ['server_uuid' => $server->uuid],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    $plan = app(DnsSteeringPolicyService::class)->planForPolicy($policy);

    expect($plan['enabled'])->toBeFalse()
        ->and($plan['can_apply'])->toBeFalse()
        ->and(data_get($plan, 'desired_records.0.type'))->toBe('A')
        ->and(data_get($plan, 'desired_records.0.name'))->toBe('app.example.com')
        ->and(data_get($plan, 'desired_records.0.content'))->toBe('198.51.100.20')
        ->and(data_get($plan, 'actions.0.action'))->toBe('update')
        ->and(data_get($plan, 'actions.0.before.content'))->toBe('203.0.113.10')
        ->and(data_get($plan, 'actions.0.after.content'))->toBe('198.51.100.20');
});

test('dns steering plan updates A record to secondary when primary is down', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering-passive',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '198.51.100.30',
        'ttl' => 60,
        'proxied' => false,
        'metadata' => [
            'dns_steering_policy_uuid' => 'pending-policy',
        ],
    ]);

    $primary = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '198.51.100.30',
    ]);
    $primary->settings->update([
        'is_reachable' => false,
        'is_usable' => false,
    ]);

    $secondary = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '198.51.100.31',
    ]);
    $secondary->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    $policy = DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'app.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_nodes' => [
            ['server_uuid' => $primary->uuid, 'priority' => 1],
            ['server_uuid' => $secondary->uuid, 'priority' => 2],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    $plan = app(DnsSteeringPolicyService::class)->planForPolicy($policy);

    expect($plan['can_apply'])->toBeTrue()
        ->and($plan['reason'])->toBe('primary_down')
        ->and($plan['reasons'])->toContain('candidate_healthy')
        ->and($plan['reasons'])->toContain('current_record_mismatch')
        ->and(data_get($plan, 'candidate_nodes.0.healthy'))->toBeFalse()
        ->and(data_get($plan, 'candidate_nodes.1.healthy'))->toBeTrue()
        ->and(data_get($plan, 'selected_nodes.0.server_uuid'))->toBe($secondary->uuid)
        ->and(data_get($plan, 'desired_records.0.content'))->toBe('198.51.100.31')
        ->and(data_get($plan, 'actions.0.action'))->toBe('update')
        ->and(data_get($plan, 'actions.0.after.content'))->toBe('198.51.100.31');
});

test('dns steering plan returns no change when primary is healthy and record matches', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering-no-change',
        'api_token' => 'cloudflare-test-token',
    ]);

    $primary = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '198.51.100.40',
    ]);
    $primary->settings->update([
        'is_reachable' => true,
        'is_usable' => true,
        'force_disabled' => false,
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '198.51.100.40',
        'ttl' => 60,
        'proxied' => false,
    ]);

    $policy = DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'app.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_nodes' => [
            ['server_uuid' => $primary->uuid, 'priority' => 1],
            ['ip' => '198.51.100.41', 'priority' => 2],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    $plan = app(DnsSteeringPolicyService::class)->planForPolicy($policy);

    expect($plan['reason'])->toBe('no_change')
        ->and($plan['reasons'])->toBe(['no_change'])
        ->and(data_get($plan, 'selected_nodes.0.server_uuid'))->toBe($primary->uuid)
        ->and(data_get($plan, 'actions.0.action'))->toBe('noop');
});

test('dns steering plan does not apply when no healthy candidates exist', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering-no-healthy',
        'api_token' => 'cloudflare-test-token',
    ]);

    $policy = DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'app.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_HEALTH_BASED,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'primary', 'ip' => '198.51.100.50', 'priority' => 1],
            ['name' => 'secondary', 'ip' => '198.51.100.51', 'priority' => 2],
        ],
        'metadata' => [
            'health_overrides' => [
                'primary' => false,
                'secondary' => false,
            ],
        ],
    ]);

    $fakeDnsZones = new class extends DnsZoneService
    {
        public int $upserts = 0;

        public function upsertManagedRecord(DnsZone $zone, array $data, int $teamId): DnsRecord
        {
            $this->upserts++;

            return DnsRecord::create([
                'dns_zone_id' => $zone->id,
                'type' => $data['type'],
                'name' => $data['name'],
                'content' => $data['content'],
                'ttl' => $data['ttl'],
                'proxied' => $data['proxied'],
            ]);
        }
    };

    $result = app(DnsSteeringPolicyService::class)->evaluatePolicies($fakeDnsZones, apply: true, teamId: $this->team->id);

    expect(data_get($result, 'planned.0.reason'))->toBe('no_healthy_candidates')
        ->and(data_get($result, 'planned.0.can_apply'))->toBeFalse()
        ->and($result['applied'])->toBeEmpty()
        ->and(data_get($result, 'skipped.0.reason'))->toBe('no_healthy_candidates')
        ->and($fakeDnsZones->upserts)->toBe(0);
});

test('dns steering evaluate command dry run does not mutate records or provider', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering-dry-run',
        'api_token' => 'cloudflare-test-token',
    ]);

    $record = DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '198.51.100.60',
        'ttl' => 60,
        'proxied' => false,
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'app.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_HEALTH_BASED,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'secondary', 'ip' => '198.51.100.61'],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    $fakeDnsZones = new class extends DnsZoneService
    {
        public int $upserts = 0;

        public function upsertManagedRecord(DnsZone $zone, array $data, int $teamId): DnsRecord
        {
            $this->upserts++;

            throw new RuntimeException('Dry-run should not call the DNS provider.');
        }
    };
    app()->instance(DnsZoneService::class, $fakeDnsZones);

    $this->artisan('dns:steering:evaluate', ['--team' => $this->team->id])
        ->expectsOutputToContain('dry-run')
        ->assertExitCode(0);

    expect($record->refresh()->content)->toBe('198.51.100.60')
        ->and($fakeDnsZones->upserts)->toBe(0);
});

test('dns steering evaluate command applies only enabled policies through dns zone service', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steering-apply',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'enabled.example.com',
        'content' => '198.51.100.70',
        'ttl' => 60,
        'proxied' => false,
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'disabled.example.com',
        'content' => '198.51.100.80',
        'ttl' => 60,
        'proxied' => false,
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'enabled.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_HEALTH_BASED,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'enabled-secondary', 'ip' => '198.51.100.71'],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'disabled.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_HEALTH_BASED,
        'enabled' => false,
        'candidate_nodes' => [
            ['name' => 'disabled-secondary', 'ip' => '198.51.100.81'],
        ],
        'metadata' => [
            'ttl' => 60,
        ],
    ]);

    $fakeDnsZones = new class extends DnsZoneService
    {
        public int $upserts = 0;

        public function upsertManagedRecord(DnsZone $zone, array $data, int $teamId): DnsRecord
        {
            $this->upserts++;

            return DnsRecord::updateOrCreate(
                [
                    'dns_zone_id' => $zone->id,
                    'type' => $data['type'],
                    'name' => $data['name'],
                ],
                [
                    'provider_record_id' => 'fake-'.$this->upserts,
                    'content' => $data['content'],
                    'ttl' => $data['ttl'],
                    'proxied' => $data['proxied'],
                    'comment' => data_get($data, 'comment'),
                    'metadata' => data_get($data, 'metadata', []),
                ],
            )->refresh();
        }
    };
    app()->instance(DnsZoneService::class, $fakeDnsZones);

    $this->artisan('dns:steering:evaluate', ['--apply' => true, '--team' => $this->team->id])
        ->expectsOutputToContain('Applied: 1')
        ->expectsOutputToContain('Skipped: 1')
        ->assertExitCode(0);

    expect(DnsRecord::where('name', 'enabled.example.com')->first()->content)->toBe('198.51.100.71')
        ->and(DnsRecord::where('name', 'disabled.example.com')->first()->content)->toBe('198.51.100.80')
        ->and($fakeDnsZones->upserts)->toBe(1);
});
