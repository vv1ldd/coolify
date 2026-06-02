<?php

use App\Enums\ProtectionActionType;
use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use App\Services\IncidentProtection\ProtectionAction;
use App\Services\IncidentProtection\ProtectionActionExecutor;
use App\Services\Provider\HostingerVpsProviderAdapter;
use App\Services\Provider\ProviderApiException;
use App\Services\Provider\SelectelVdsProviderAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);
uses(RefreshDatabase::class);

function providerAction(ProtectionActionType $type): ProtectionAction
{
    return new ProtectionAction(
        type: $type,
        label: $type->value,
        target: ['server_id' => 1],
        requiresApproval: true,
        dryRun: true,
        dangerous: true,
    );
}

test('selectel vds list and inspect parse server identifiers addresses and status', function () {
    Http::fake([
        'https://api.vscale.io/v1/scalets' => Http::response([
            [
                'ctid' => 10087,
                'name' => 'mytestserver',
                'hostname' => 'cs10087.vscale.ru',
                'status' => 'started',
                'active' => true,
                'public_address' => ['address' => '95.213.191.70'],
                'private_address' => ['address' => '10.0.0.7'],
                'location' => 'spb0',
                'rplan' => 'medium',
            ],
        ]),
        'https://api.vscale.io/v1/scalets/10087' => Http::response([
            'ctid' => 10087,
            'name' => 'mytestserver',
            'status' => 'started',
            'public_address' => ['address' => '95.213.191.70'],
        ]),
    ]);

    $adapter = new SelectelVdsProviderAdapter;
    $token = new CloudProviderToken(['provider' => 'selectel_vds', 'token' => 'selectel-secret-token']);

    $servers = $adapter->listServers($token);
    $server = $adapter->inspectServer(10087, $token);

    expect($servers[0]->toArray())->toMatchArray([
        'provider' => 'selectel_vds',
        'id' => '10087',
        'name' => 'mytestserver',
        'status' => 'started',
        'primary_ip' => '95.213.191.70',
        'public_ips' => ['95.213.191.70'],
        'private_ips' => ['10.0.0.7'],
    ])->and($server->id)->toBe('10087');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'selectel-secret-token'));
});

test('selectel vds poweroff and reboot use documented patch request shape', function () {
    Http::fake([
        'https://api.vscale.io/v1/scalets/10087/stop' => Http::response([
            'ctid' => 10087,
            'status' => 'stopped',
            'name' => 'mytestserver',
        ]),
        'https://api.vscale.io/v1/scalets/10087/restart' => Http::response([
            'ctid' => 10087,
            'status' => 'started',
            'name' => 'mytestserver',
        ]),
    ]);

    $adapter = new SelectelVdsProviderAdapter(new CloudProviderToken([
        'provider' => 'selectel_vds',
        'token' => 'selectel-secret-token',
    ]));
    $server = Server::factory()->make(['server_metadata' => ['provider_server_id' => 10087]]);

    $poweroff = $adapter->powerOffServer($server, providerAction(ProtectionActionType::PROVIDER_POWEROFF_SERVER));
    $reboot = $adapter->rebootServer(10087);

    expect($poweroff)->toMatchArray([
        'provider' => 'selectel_vds',
        'action' => 'poweroff',
        'server_id' => '10087',
        'accepted' => true,
        'status' => 'stopped',
    ])->and($reboot->accepted)->toBeTrue();

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/scalets/10087/stop')
        && data_get($request->data(), 'id') === 10087);
    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_ends_with($request->url(), '/scalets/10087/restart')
        && data_get($request->data(), 'id') === 10087);
});

test('hostinger vps list and inspect parse server identifiers addresses and status', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            [
                'id' => 1268054,
                'hostname' => 'app-vps',
                'state' => 'running',
                'plan' => 'KVM 2',
                'ipv4' => [['id' => 10, 'address' => '203.0.113.10']],
                'ipv6' => [['id' => 11, 'address' => '2001:db8::10']],
            ],
        ]),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/1268054' => Http::response([
            'id' => 1268054,
            'hostname' => 'app-vps',
            'state' => 'running',
            'ipv4' => [['address' => '203.0.113.10']],
        ]),
    ]);

    $adapter = new HostingerVpsProviderAdapter;
    $token = new CloudProviderToken(['provider' => 'hostinger_vps', 'token' => 'hostinger-secret-token']);

    $servers = $adapter->listServers($token);
    $server = $adapter->inspectServer(1268054, $token);

    expect($servers[0]->toArray())->toMatchArray([
        'provider' => 'hostinger_vps',
        'id' => '1268054',
        'name' => 'app-vps',
        'status' => 'running',
        'primary_ip' => '203.0.113.10',
        'public_ips' => ['203.0.113.10', '2001:db8::10'],
        'private_ips' => [],
    ])->and($server->id)->toBe('1268054');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer hostinger-secret-token'));
});

test('hostinger vps poweroff and reboot use documented post request shape', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/1268054/stop' => Http::response([
            'id' => 321,
            'name' => 'stop',
            'state' => 'running',
        ]),
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/1268054/restart' => Http::response([
            'id' => 322,
            'name' => 'restart',
            'state' => 'running',
        ]),
    ]);

    $adapter = new HostingerVpsProviderAdapter(new CloudProviderToken([
        'provider' => 'hostinger_vps',
        'token' => 'hostinger-secret-token',
    ]));
    $server = Server::factory()->make(['server_metadata' => ['provider_server_id' => 1268054]]);

    $poweroff = $adapter->powerOffServer($server, providerAction(ProtectionActionType::PROVIDER_POWEROFF_SERVER));
    $reboot = $adapter->rebootServer(1268054);

    expect($poweroff)->toMatchArray([
        'provider' => 'hostinger_vps',
        'action' => 'poweroff',
        'server_id' => '1268054',
        'accepted' => true,
        'action_id' => '321',
    ])->and($reboot->actionId)->toBe('322');

    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/vps/v1/virtual-machines/1268054/stop')
        && $request->data() === []);
    Http::assertSent(fn ($request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/api/vps/v1/virtual-machines/1268054/restart')
        && $request->data() === []);
});

test('provider adapter exceptions and results do not leak tokens', function () {
    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([
            'message' => 'hostinger-secret-token should not be surfaced',
        ], 500),
    ]);

    $adapter = new HostingerVpsProviderAdapter;
    $token = new CloudProviderToken(['provider' => 'hostinger_vps', 'token' => 'hostinger-secret-token']);

    try {
        $adapter->listServers($token);
        $this->fail('Expected provider exception was not thrown.');
    } catch (ProviderApiException $exception) {
        expect($exception->getMessage())->not->toContain('hostinger-secret-token');
    }

    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines/1268054/restart' => Http::response([
            'id' => 322,
            'name' => 'restart',
            'state' => 'running',
        ]),
    ]);

    $result = (new HostingerVpsProviderAdapter($token))->rebootServer(1268054)->toArray();

    expect(json_encode($result))->not->toContain('hostinger-secret-token');
});

test('incident protection executor blocks dangerous provider action before adapter http call without approval', function () {
    Http::fake();

    $team = Team::factory()->create();
    $cloudProviderToken = CloudProviderToken::factory()->create([
        'team_id' => $team->id,
        'provider' => 'hostinger_vps',
        'token' => 'hostinger-secret-token',
    ]);
    $server = Server::factory()->create([
        'team_id' => $team->id,
        'cloud_provider_token_id' => $cloudProviderToken->id,
        'server_metadata' => ['provider_server_id' => 1268054],
    ]);
    $action = new ProtectionAction(
        type: ProtectionActionType::PROVIDER_POWEROFF_SERVER,
        label: 'Power off provider server',
        target: ['server_id' => $server->id],
        requiresApproval: true,
        dryRun: true,
        dangerous: true,
    );

    $result = app(ProtectionActionExecutor::class)->execute($action, ['team_id' => $team->id]);

    expect($result->status)->toBe('blocked')
        ->and($result->blocked)->toBeTrue()
        ->and($result->dryRun)->toBeTrue();
    Http::assertNothingSent();
});
