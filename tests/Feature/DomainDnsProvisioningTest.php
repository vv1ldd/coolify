<?php

use App\Models\Application;
use App\Models\CloudflareSetting;
use App\Models\DnsZone;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\Team;
use App\Models\User;
use App\Services\Dns\DomainDnsProvisioningService;
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
});

test('application domain settings can provision cloudflare dns record', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.44',
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.example.com',
    ]);
    DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-app',
        'api_token' => 'cloudflare-test-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-app/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-app',
                    'type' => 'A',
                    'name' => 'app.example.com',
                    'content' => '203.0.113.44',
                    'ttl' => 1,
                    'proxied' => true,
                ],
            ]),
    ]);

    $result = app(DomainDnsProvisioningService::class)
        ->provisionApplication($application, $this->team->id, proxied: true);

    expect($result['configured_count'])->toBe(1)
        ->and($result['skipped_count'])->toBe(0);

    $this->assertDatabaseHas('dns_records', [
        'type' => 'A',
        'name' => 'app.example.com',
        'content' => '203.0.113.44',
        'application_id' => $application->id,
        'proxied' => true,
        'provider_record_id' => 'record-app',
    ]);

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && data_get($request->data(), 'proxied') === true);
});

test('service application domain settings can provision cloudflare dns record with resource metadata', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.55',
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);
    $serviceApplication = ServiceApplication::create([
        'service_id' => $service->id,
        'name' => 'web',
        'fqdn' => 'https://web.example.com',
        'image' => 'nginx:latest',
    ]);
    DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-service',
        'api_token' => 'cloudflare-test-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones/zone-service/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-service',
                    'type' => 'A',
                    'name' => 'web.example.com',
                    'content' => '203.0.113.55',
                    'ttl' => 1,
                    'proxied' => false,
                ],
            ]),
    ]);

    $result = app(DomainDnsProvisioningService::class)
        ->provisionServiceApplication($serviceApplication, $this->team->id);

    expect($result['configured_count'])->toBe(1)
        ->and($result['skipped_count'])->toBe(0);

    $record = \App\Models\DnsRecord::where('provider_record_id', 'record-service')->firstOrFail();

    expect($record->application_id)->toBeNull()
        ->and(data_get($record->metadata, 'resource_type'))->toBe('service_application')
        ->and(data_get($record->metadata, 'resource_uuid'))->toBe($serviceApplication->uuid);
});

test('domain dns provisioning warns when no connected cloudflare zone matches', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.66',
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://app.unmanaged.test',
    ]);

    Http::fake();

    $result = app(DomainDnsProvisioningService::class)
        ->provisionApplication($application, $this->team->id);

    expect($result['configured_count'])->toBe(0)
        ->and($result['skipped'])->toBe(['app.unmanaged.test'])
        ->and($result['message'])->toContain('No matching Cloudflare zone');

    Http::assertNothingSent();
});

test('domain dns provisioning reports matching cloudflare zones for resource settings', function () {
    DnsZone::create([
        'team_id' => $this->team->id,
        'provider' => 'cloudflare',
        'name' => 'example.com',
        'provider_zone_id' => 'zone-example',
        'api_token' => 'cloudflare-test-token',
    ]);

    $status = app(DomainDnsProvisioningService::class)
        ->availabilityForDomains($this->team->id, 'https://app.example.com,https://missing.test');

    expect($status['available'])->toBeTrue()
        ->and(data_get($status, 'matches.0.host'))->toBe('app.example.com')
        ->and(data_get($status, 'matches.0.zone'))->toBe('example.com')
        ->and($status['unmatched'])->toBe(['missing.test']);
});

test('domain dns provisioning discovers matching cloudflare zone from saved token', function () {
    $server = Server::factory()->create([
        'team_id' => $this->team->id,
        'ip' => '203.0.113.77',
    ]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $project = Project::factory()->create(['team_id' => $this->team->id]);
    $environment = $project->environments()->first();

    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'fqdn' => 'https://auto.example.com',
    ]);
    CloudflareSetting::create([
        'team_id' => $this->team->id,
        'api_token' => 'cloudflare-secret-token',
    ]);

    Http::fake([
        'https://api.cloudflare.com/client/v4/zones' => Http::response([
            'success' => true,
            'result' => [
                ['id' => 'zone-auto', 'name' => 'example.com', 'status' => 'active'],
            ],
        ]),
        'https://api.cloudflare.com/client/v4/zones/zone-auto/dns_records*' => Http::sequence()
            ->push(['success' => true, 'result' => []])
            ->push([
                'success' => true,
                'result' => [
                    'id' => 'record-auto',
                    'type' => 'A',
                    'name' => 'auto.example.com',
                    'content' => '203.0.113.77',
                    'ttl' => 1,
                    'proxied' => false,
                ],
            ]),
    ]);

    $result = app(DomainDnsProvisioningService::class)
        ->provisionApplication($application, $this->team->id);

    expect($result['configured_count'])->toBe(1);

    $this->assertDatabaseHas('dns_zones', [
        'team_id' => $this->team->id,
        'name' => 'example.com',
        'provider_zone_id' => 'zone-auto',
    ]);
    $this->assertDatabaseHas('dns_records', [
        'name' => 'auto.example.com',
        'provider_record_id' => 'record-auto',
    ]);
});
