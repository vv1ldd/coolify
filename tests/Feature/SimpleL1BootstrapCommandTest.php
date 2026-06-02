<?php

use App\Models\CloudflareSetting;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    Http::preventStrayRequests();
});

test('simple l1 bootstrap creates cloudflare setting zone and steering policy', function () {
    $team = Team::factory()->create();

    Http::fake([
        'api.cloudflare.com/client/v4/zones' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-simple-l1', 'name' => 'simplel1.online'],
            ],
        ]),
    ]);

    $this->artisan('sovereign:simple-l1-bootstrap', [
        '--team' => $team->id,
        '--domain' => 'simplel1.online',
        '--token' => 'cloudflare-token',
        '--ip' => ['primary=203.0.113.10'],
        '--enable' => true,
    ])->assertExitCode(0);

    expect(CloudflareSetting::whereTeamId($team->id)->exists())->toBeTrue();

    $zone = DnsZone::whereTeamId($team->id)->where('name', 'simplel1.online')->first();
    expect($zone)->not->toBeNull()
        ->and($zone->provider_zone_id)->toBe('zone-simple-l1');

    $policy = DnsSteeringPolicy::whereTeamId($team->id)
        ->where('resource_type', 'simple_l1')
        ->first();

    expect($policy)->not->toBeNull()
        ->and($policy->enabled)->toBeTrue()
        ->and($policy->strategy)->toBe(DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE)
        ->and(data_get($policy->candidate_nodes, '0.ip'))->toBe('203.0.113.10')
        ->and(data_get($policy->candidate_nodes, '0.health_url'))->toBe('http://203.0.113.10/healthcheck')
        ->and(data_get($policy->candidate_nodes, '0.health_host'))->toBe('simplel1.online')
        ->and(data_get($policy->metadata, 'health_endpoint'))->toBe('https://simplel1.online/sl1/status');
});
