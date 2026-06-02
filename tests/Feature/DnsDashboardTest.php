<?php

use App\Models\Application;
use App\Models\ControlPlanePeer;
use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Livewire\Dns\Show as DnsShow;
use App\Models\DnsZone;
use App\Models\EdgeControlAction;
use App\Models\EdgePolicy;
use App\Models\EdgeProjection;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\ResourceArbitrationDecision;
use App\Models\ResourceReconciliationAssessment;
use App\Models\ResourceRoutingPolicy;
use App\Models\Team;
use App\Models\User;
use App\Services\EdgeProtection\EdgePolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

test('dns provider dashboard renders for authenticated team users', function () {
    $project = Project::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Meanly Storefront',
    ]);
    $environment = $project->environments()->first();
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'name' => 'Storefront',
        'fqdn' => 'https://app.example.com',
    ]);
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-dashboard',
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
    $simpleL1Zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'simplel1.online',
        'provider_zone_id' => 'zone-simple-l1',
        'api_token' => 'cloudflare-test-token',
    ]);
    DnsRecord::create([
        'dns_zone_id' => $simpleL1Zone->id,
        'type' => 'A',
        'name' => 'simplel1.online',
        'content' => '198.51.100.10',
        'ttl' => 60,
        'proxied' => false,
    ]);
    DnsSteeringPolicy::create([
        'team_id' => $this->team->id,
        'dns_zone_id' => $simpleL1Zone->id,
        'domain' => 'simplel1.online',
        'resource_type' => 'simple_l1',
        'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_nodes' => [
            ['name' => 'primary', 'ip' => '198.51.100.10'],
            ['name' => 'backup', 'ip' => '198.51.100.11'],
        ],
    ]);
    ControlPlanePeer::create([
        'team_id' => $this->team->id,
        'name' => 'edge-1',
        'endpoint_url' => 'https://edge-1.example.com',
        'public_ip' => '198.51.100.20',
        'role' => ControlPlanePeer::ROLE_EDGE_AGENT,
        'status' => ControlPlanePeer::STATUS_ONLINE,
        'last_seen_at' => now(),
        'capabilities' => [ControlPlanePeer::CAPABILITY_EDGE_RUNTIME],
    ]);
    $projection = EdgeProjection::create([
        'team_id' => $this->team->id,
        'domain' => 'app.example.com',
        'intent_type' => 'domain_edge_policy',
        'intent_uuid' => 'app.example.com',
        'intent_version' => 1,
        'intent_hash' => str_repeat('a', 64),
        'projection_type' => EdgeProjection::TYPE_EDGE_RUNTIME,
        'adapter' => EdgeProjection::ADAPTER_TRAEFIK,
        'projection_version' => 1,
        'projection_hash' => str_repeat('b', 64),
        'payload' => ['host' => 'app.example.com'],
        'required_capabilities' => [ControlPlanePeer::CAPABILITY_EDGE_RUNTIME],
        'status' => EdgeProjection::STATUS_APPLIED,
        'generated_at' => now(),
        'applied_at' => now(),
        'applied_projection_hash' => str_repeat('b', 64),
    ]);
    EdgeControlAction::create([
        'team_id' => $this->team->id,
        'edge_projection_id' => $projection->id,
        'domain' => 'app.example.com',
        'action_type' => EdgeControlAction::TYPE_EDGE_RUNTIME_APPLY,
        'adapter' => EdgeProjection::ADAPTER_TRAEFIK,
        'status' => EdgeControlAction::STATUS_SUCCEEDED,
        'request' => ['projection_uuid' => $projection->uuid],
        'outcome' => ['ok' => true],
        'projection_hash' => $projection->projection_hash,
        'applied_projection_hash' => $projection->projection_hash,
        'executed_at' => now(),
    ]);
    $resourcePolicy = ResourceRoutingPolicy::create([
        'team_id' => $this->team->id,
        'resource_type' => ResourceRoutingPolicy::RESOURCE_MARKETPLACE,
        'resource_uuid' => 'marketplace-main',
        'domain' => 'marketplace.example.com',
        'routing_layer' => ResourceRoutingPolicy::LAYER_L7,
        'strategy' => ResourceRoutingPolicy::STRATEGY_ACTIVE_PASSIVE,
        'enabled' => true,
        'candidate_backends' => [
            ['backend' => 'node-a'],
            ['backend' => 'node-b'],
        ],
    ]);
    $assessment = ResourceReconciliationAssessment::create([
        'team_id' => $this->team->id,
        'resource_routing_policy_id' => $resourcePolicy->id,
        'resource_type' => ResourceRoutingPolicy::RESOURCE_MARKETPLACE,
        'resource_uuid' => 'marketplace-main',
        'scope' => 'resource_routing',
        'assessment_hash' => str_repeat('c', 64),
        'observation_refs' => [],
        'conflicts' => [],
        'candidates' => ['healthy_backends' => ['node-b']],
        'assessment' => ['target_backend' => 'node-b'],
        'severity' => 50,
        'assessed_at' => now(),
    ]);
    ResourceArbitrationDecision::create([
        'team_id' => $this->team->id,
        'resource_routing_policy_id' => $resourcePolicy->id,
        'resource_reconciliation_assessment_id' => $assessment->id,
        'scope' => 'resource_routing',
        'authority_scope' => 'marketplace',
        'authority_actor' => 'edge-operator-1',
        'authority_basis' => 'delegated_marketplace_authority_v3',
        'decision' => ResourceArbitrationDecision::DECISION_SWITCH_BACKEND,
        'decision_hash' => str_repeat('d', 64),
        'reason' => 'healthy_target_available',
        'assessment' => ['target_backend' => 'node-b'],
        'rationale' => ['assessment_hash' => $assessment->assessment_hash],
        'decided_at' => now(),
    ]);

    $this->get(route('dns.index'))
        ->assertOk()
        ->assertSee('DNS & Edge Control Plane', false)
        ->assertSee('edge_runtime')
        ->assertSee('Project & Service Domains', false)
        ->assertSee('app.example.com')
        ->assertSee('Storefront')
        ->assertSee('Zone: example.com')
        ->assertSee('Projection: traefik v1')
        ->assertSee(substr($projection->projection_hash, 0, 12))
        ->assertSee('Last action: traefik')
        ->assertSee('Resource Continuity')
        ->assertSee('marketplace')
        ->assertSee('assessment='.substr($assessment->assessment_hash, 0, 12))
        ->assertSee('decision='.substr(str_repeat('d', 64), 0, 12))
        ->assertSee('delegated_marketplace_authority_v3')
        ->assertSee('healthy_target_available')
        ->assertSee('Simple L1 Domain')
        ->assertSee('simplel1.online')
        ->assertSee('198.51.100.10')
        ->assertSee('active passive')
        ->assertSee('Token')
        ->assertDontSee('Cloudflare API token')
        ->assertDontSee('Save Token')
        ->assertDontSee('Validate Saved Token')
        ->assertDontSee('Load zones from token')
        ->assertDontSee('Zone name')
        ->assertDontSee('zone-dashboard')
        ->assertDontSee('menu-item-label tracking-tight">Domains', false);
});

test('dns zone detail renders ru dns only and edge protection guidance', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.ru',
        'provider_zone_id' => 'zone-ru',
        'api_token' => 'cloudflare-test-token',
    ]);

    $this->get(route('dns.show', ['zone_uuid' => $zone->uuid]))
        ->assertOk()
        ->assertSee('This RU zone is DNS-only at Cloudflare by policy')
        ->assertSee('Edge Protection is separate from DNS proxying');
});

test('dns zone detail renders matching edge policy as read only', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-policy',
        'api_token' => 'cloudflare-test-token',
    ]);

    app(EdgePolicyService::class)->createForTeam($this->team->id, [
        'name' => 'Zone default policy',
        'mode' => EdgePolicy::MODE_STRICT,
        'scope_type' => EdgePolicy::SCOPE_DOMAIN,
        'scope_value' => 'example.com',
    ]);

    $this->get(route('dns.show', ['zone_uuid' => $zone->uuid]))
        ->assertOk()
        ->assertSee('Edge Policy')
        ->assertSee('Zone default policy')
        ->assertSee('Domain protection is resolved from EdgePolicy');
});

test('dns record upsert forces ru records to cloudflare dns only', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.ru',
        'provider_zone_id' => 'zone-ru',
        'api_token' => 'cloudflare-test-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-ru/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-ru-ui',
                    'type' => 'A',
                    'name' => 'app.example.ru',
                    'content' => '203.0.113.10',
                    'ttl' => 1,
                    'proxied' => false,
                ],
            ]),
    ]);

    Livewire::test(DnsShow::class, ['zone_uuid' => $zone->uuid])
        ->set('type', 'A')
        ->set('record_name', 'app.example.ru')
        ->set('content', '203.0.113.10')
        ->set('ttl', 1)
        ->set('proxied', true)
        ->call('upsertRecord');

    $this->assertDatabaseHas('dns_records', [
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.ru',
        'content' => '203.0.113.10',
        'proxied' => false,
        'provider_record_id' => 'record-ru-ui',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && data_get($request->data(), 'proxied') === false);
});

test('dns zone page supports AAAA records from ui and cloudflare sync', function () {
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-ipv6',
        'api_token' => 'cloudflare-test-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-ipv6/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-ipv6-ui',
                    'type' => 'AAAA',
                    'name' => 'ipv6.example.com',
                    'content' => '2001:db8::10',
                    'ttl' => 1,
                    'proxied' => false,
                ],
            ])
            ->push([
                'success' => true,
                'result' => [
                    [
                        'id' => 'record-ipv6-provider',
                        'type' => 'AAAA',
                        'name' => 'provider.example.com',
                        'content' => '2001:db8::20',
                        'ttl' => 1,
                        'proxied' => false,
                    ],
                ],
            ]),
    ]);

    Livewire::test(DnsShow::class, ['zone_uuid' => $zone->uuid])
        ->set('type', 'AAAA')
        ->set('record_name', 'ipv6.example.com')
        ->set('content', '2001:db8::10')
        ->set('ttl', 1)
        ->call('upsertRecord')
        ->call('syncProviderRecords');

    $this->assertDatabaseHas('dns_records', [
        'dns_zone_id' => $zone->id,
        'type' => 'AAAA',
        'name' => 'ipv6.example.com',
        'content' => '2001:db8::10',
        'provider_record_id' => 'record-ipv6-ui',
    ]);

    $this->assertDatabaseHas('dns_records', [
        'dns_zone_id' => $zone->id,
        'type' => 'AAAA',
        'name' => 'provider.example.com',
        'content' => '2001:db8::20',
        'provider_record_id' => 'record-ipv6-provider',
    ]);
});
