<?php

use App\Models\Application;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0, 'is_api_enabled' => true]));

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);

    session(['currentTeam' => $this->team]);
    $this->token = $this->user->createToken('dns-test-token', ['*']);
    $this->headers = [
        'Authorization' => 'Bearer '.$this->token->plainTextToken,
        'Content-Type' => 'application/json',
    ];
});

test('creates a cloudflare dns zone without returning the api token', function () {
    $response = $this->withHeaders($this->headers)->postJson('/api/v1/dns-zones', [
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'cloudflare-zone-id',
        'api_token' => 'cloudflare-test-token',
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'name' => 'example.com',
            'provider' => 'cloudflare',
            'provider_zone_id' => 'cloudflare-zone-id',
        ])
        ->assertJsonMissing(['api_token' => 'cloudflare-test-token']);

    $this->assertDatabaseHas('dns_zones', [
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'cloudflare-zone-id',
    ]);
});

test('upserts a ru dns record as dns only even when proxied is requested', function () {
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
                    'id' => 'record-ru',
                    'type' => 'A',
                    'name' => 'app.example.ru',
                    'content' => '203.0.113.10',
                    'ttl' => 1,
                    'proxied' => false,
                ],
            ]),
    ]);

    $response = $this->withHeaders($this->headers)->postJson("/api/v1/dns-zones/{$zone->uuid}/records", [
        'type' => 'A',
        'name' => 'app.example.ru',
        'content' => '203.0.113.10',
        'ttl' => 1,
        'proxied' => true,
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'type' => 'A',
            'name' => 'app.example.ru',
            'proxied' => false,
            'provider_record_id' => 'record-ru',
        ]);

    $this->assertDatabaseHas('dns_records', [
        'dns_zone_id' => $zone->id,
        'type' => 'A',
        'name' => 'app.example.ru',
        'content' => '203.0.113.10',
        'proxied' => false,
        'provider_record_id' => 'record-ru',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && data_get($request->data(), 'proxied') === false);
});

test('does not list dns zones from another team', function () {
    DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'owned.example',
        'provider_zone_id' => 'owned-zone',
        'api_token' => 'owned-token',
    ]);

    $otherTeam = Team::factory()->create();
    DnsZone::create([
        'team_id' => $otherTeam->id,
        'provider' => 'cloudflare',
        'name' => 'other.example',
        'provider_zone_id' => 'other-zone',
        'api_token' => 'other-token',
    ]);

    $response = $this->withHeaders($this->headers)->getJson('/api/v1/dns-zones');

    $response->assertOk()
        ->assertJsonCount(1)
        ->assertJsonFragment(['name' => 'owned.example'])
        ->assertJsonMissing(['name' => 'other.example']);
});

test('can associate a managed dns record to an application in the same team', function () {
    $project = Project::create([
        'team_id' => $this->team->id,
        'name' => 'DNS Project',
    ]);
    $application = Application::factory()->create([
        'environment_id' => $project->environments()->first()->id,
        'name' => 'DNS App',
    ]);
    $zone = DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-com',
        'api_token' => 'cloudflare-test-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-com/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push(['success' => true, 'result' => ['id' => 'record-com', 'type' => 'CNAME', 'name' => 'app.example.com']]),
    ]);

    $response = $this->withHeaders($this->headers)->postJson("/api/v1/dns-zones/{$zone->uuid}/records", [
        'type' => 'CNAME',
        'name' => 'app.example.com',
        'content' => 'edge.example.net',
        'ttl' => 300,
        'proxied' => false,
        'application_uuid' => $application->uuid,
    ]);

    $response->assertCreated()
        ->assertJsonFragment([
            'application_uuid' => $application->uuid,
            'provider_record_id' => 'record-com',
        ]);

    $this->assertDatabaseHas('dns_records', [
        'dns_zone_id' => $zone->id,
        'application_id' => $application->id,
        'provider_record_id' => 'record-com',
    ]);
});
