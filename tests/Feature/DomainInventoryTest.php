<?php

use App\Livewire\Domains\Index as DomainsIndex;
use App\Models\Application;
use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\EdgePolicy;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use App\Services\DomainInventoryService;
use App\Services\EdgeProtection\EdgePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

test('domain inventory service combines application service and dns metadata', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Storefront',
    ]);
    $environment = $project->environments()->first();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Checkout API',
        'fqdn' => 'https://app.example.com,https://admin.example.com/dashboard',
        'custom_labels' => base64_encode(implode("\n", [
            'traefik.http.middlewares.coolify-edge-app-ratelimit.ratelimit.average=50',
            'traefik.http.middlewares.coolify-edge-app-ratelimit.ratelimit.burst=100',
            'traefik.http.middlewares.coolify-edge-app-inflight.inflightreq.amount=25',
        ])),
    ]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'analytics',
    ]);

    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'worker',
        'human_name' => 'Analytics Worker',
        'fqdn' => 'https://worker.example.com',
    ]);

    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-inventory',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'application_id' => $application->id,
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.10',
        'ttl' => 1,
        'proxied' => true,
    ]);

    $entries = app(DomainInventoryService::class)->forTeam($this->team->id);
    $appEntry = $entries->firstWhere('domain', 'app.example.com');
    $workerEntry = $entries->firstWhere('domain', 'worker.example.com');

    expect($appEntry)->not()->toBeNull()
        ->and(data_get($appEntry, 'uses.0.resource_name'))->toBe('Checkout API')
        ->and(data_get($appEntry, 'uses.0.source'))->toBe('App')
        ->and(data_get($appEntry, 'uses.0.project_name'))->toBe('Storefront')
        ->and(data_get($appEntry, 'uses.0.resource_uuid'))->toHaveLength(8)
        ->and(data_get($appEntry, 'dns_zones.0.name'))->toBe('example.com')
        ->and(data_get($appEntry, 'uses.0.edge.active'))->toBeTrue()
        ->and(data_get($appEntry, 'uses.0.edge.summary'))->toContain('rate 50/s burst 100')
        ->and(data_get($appEntry, 'dns_records.0.proxy_status'))->toBe('Proxied')
        ->and(data_get($appEntry, 'dns_records.0.application_uuid_short'))->toHaveLength(8)
        ->and(data_get($workerEntry, 'uses.0.service_name'))->toBe('analytics')
        ->and(data_get($workerEntry, 'uses.0.container_name'))->toBe('worker');
});

test('domain inventory page renders tags dns records and conflicts', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Conflicted Project',
    ]);
    $environment = $project->environments()->first();

    Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Public App',
        'fqdn' => 'https://shared.example.com',
    ]);

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'public-service',
    ]);

    ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'fqdn' => 'https://shared.example.com',
    ]);

    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-shared',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsRecord::create([
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'shared.example.com',
        'content' => '203.0.113.20',
        'ttl' => 1,
        'proxied' => false,
    ]);

    app(EdgePolicyService::class)->createForTeam($this->team->id, [
        'name' => 'Shared strict policy',
        'mode' => EdgePolicy::MODE_STRICT,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'shared.example.com',
    ]);

    $this->get(route('domains.index'))
        ->assertOk()
        ->assertSee('Domain Bindings')
        ->assertSee('shared.example.com')
        ->assertSee('Zone: example.com')
        ->assertSee('Public App')
        ->assertSee('public-service')
        ->assertSee('Conflict: 2 resources use this domain')
        ->assertSee('EdgePolicy: strict')
        ->assertSee('Shared strict policy')
        ->assertSee('DNS-only')
        ->assertSee('203.0.113.20');
});

test('domain inventory page can search by resource and project metadata', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Searchable Project',
    ]);
    $environment = $project->environments()->first();

    Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Needle API',
        'fqdn' => 'https://needle.example.com',
    ]);

    Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Other API',
        'fqdn' => 'https://other.example.com',
    ]);

    Livewire::test(DomainsIndex::class)
        ->set('search', 'Needle')
        ->assertSee('needle.example.com')
        ->assertDontSee('other.example.com');
});

test('domain inventory renders dns steering preview without enabling rotation', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Steered Project',
    ]);
    $environment = $project->environments()->first();

    Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Steered App',
        'fqdn' => 'https://steered.example.com',
    ]);

    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-steered',
        'api_token' => 'cloudflare-test-token',
    ]);

    DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $zone->id,
        'domain' => 'steered.example.com',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => false,
        'candidate_nodes' => [
            ['name' => 'edge-1', 'ip' => '198.51.100.50', 'healthy' => true],
            ['name' => 'edge-2', 'ip' => '198.51.100.51', 'healthy' => true],
        ],
    ]);

    $this->get(route('domains.index'))
        ->assertOk()
        ->assertSee('steered.example.com')
        ->assertSee('DNS Steering: disabled')
        ->assertSee('active passive')
        ->assertSee('Future DNS steering candidates')
        ->assertSee('198.51.100.50');
});
