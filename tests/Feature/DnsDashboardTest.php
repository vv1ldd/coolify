<?php

use App\Livewire\Dns\Index as DnsIndex;
use App\Livewire\Dns\Show as DnsShow;
use App\Models\DnsZone;
use App\Models\EdgePolicy;
use App\Models\InstanceSettings;
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

test('dns zones dashboard renders for authenticated team users', function () {
    DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-dashboard',
        'api_token' => 'cloudflare-test-token',
    ]);

    $this->get(route('dns.index'))
        ->assertOk()
        ->assertSee('DNS Zones')
        ->assertSee('example.com')
        ->assertSee('Create DNS Zone');
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

test('dns zone can be created without exposing the token in rendered output', function () {
    Livewire::test(DnsIndex::class)
        ->set('name', 'created.example')
        ->set('provider_zone_id', 'zone-created')
        ->set('api_token', 'cloudflare-secret-token')
        ->call('createZone')
        ->assertRedirect();

    $this->assertDatabaseHas('dns_zones', [
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'created.example',
        'provider_zone_id' => 'zone-created',
    ]);

    $this->get(route('dns.index'))
        ->assertOk()
        ->assertDontSee('cloudflare-secret-token');
});

test('dns zone form can load cloudflare zones from token', function () {
    Http::fake([
        'https://api.cloudflare.com/client/v4/zones' => Http::response([
            'success' => true,
            'result' => [
                [
                    'id' => 'zone-meanly',
                    'name' => 'meanly.ru',
                    'status' => 'active',
                ],
                [
                    'id' => 'zone-digitienda',
                    'name' => 'digitienda.ar',
                    'status' => 'active',
                ],
            ],
        ]),
    ]);

    Livewire::test(DnsIndex::class)
        ->set('api_token', 'cloudflare-secret-token')
        ->call('loadProviderZones')
        ->assertSet('providerZones.0.name', 'digitienda.ar')
        ->assertSet('providerZones.1.name', 'meanly.ru')
        ->set('selectedProviderZoneId', 'zone-meanly')
        ->assertSet('name', 'meanly.ru')
        ->assertSet('provider_zone_id', 'zone-meanly');
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
