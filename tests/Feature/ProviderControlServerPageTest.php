<?php

use App\Livewire\Server\Show;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::query()->create([
        'id' => 0,
        'is_registration_enabled' => true,
    ]));

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);
});

it('renders provider control panel on the server page', function () {
    CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'selectel_vds',
        'name' => 'Selectel Ops Token',
        'token' => 'test-selectel-token',
    ]);

    $response = $this->get(route('server.show', ['server_uuid' => $this->server->uuid]));

    $response->assertSuccessful();
    $response->assertSee('Provider Control');
    $response->assertSee('Selectel Ops Token');
    $response->assertSee('Plan Poweroff');
});

it('can save provider control binding on a server', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'selectel_vds',
        'name' => 'Selectel Ops Token',
        'token' => 'test-selectel-token',
    ]);

    Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->set('selectedProviderControlTokenId', $token->id)
        ->set('providerControlServerId', '10087')
        ->call('saveProviderControlBinding')
        ->assertDispatched('success');

    $this->server->refresh();

    expect($this->server->cloud_provider_token_id)->toBe($token->id)
        ->and(data_get($this->server->server_metadata, 'provider_server_id'))->toBe('10087')
        ->and(data_get($this->server->server_metadata, 'provider_control.provider'))->toBe('selectel_vds');
});

it('inspects provider status through fake provider http without leaking the token', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'selectel_vds',
        'name' => 'Selectel Ops Token',
        'token' => 'selectel-secret-token',
    ]);
    $this->server->update([
        'cloud_provider_token_id' => $token->id,
        'server_metadata' => ['provider_server_id' => '10087'],
    ]);

    Http::fake([
        'https://api.vscale.io/v1/scalets/10087' => Http::response([
            'ctid' => 10087,
            'name' => 'edge-1',
            'status' => 'started',
            'public_address' => ['address' => '95.213.191.70'],
            'location' => 'spb0',
        ]),
    ]);

    $component = Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('inspectProviderServer')
        ->assertDispatched('success')
        ->assertSet('providerControlStatus.id', '10087')
        ->assertSet('providerControlStatus.status', 'started');

    expect($component->html())->not->toContain('selectel-secret-token')
        ->and(json_encode($component->get('providerControlStatus')))->not->toContain('selectel-secret-token');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'selectel-secret-token')
        && str_ends_with($request->url(), '/scalets/10087'));
});

it('blocks destructive provider action planning without approval and sends no provider mutation', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger_vps',
        'name' => 'Hostinger Ops Token',
        'token' => 'hostinger-secret-token',
    ]);
    $this->server->update([
        'cloud_provider_token_id' => $token->id,
        'server_metadata' => ['provider_server_id' => '1268054'],
    ]);

    Http::fake();

    $component = Livewire::test(Show::class, ['server_uuid' => $this->server->uuid])
        ->call('planProviderControlAction', 'poweroff')
        ->assertDispatched('info');

    $plan = $component->get('providerControlActionPlan');

    expect(data_get($plan, 'action'))->toBe('poweroff')
        ->and(data_get($plan, 'execution_guard.status'))->toBe('blocked')
        ->and(data_get($plan, 'execution_guard.blocked'))->toBeTrue()
        ->and(data_get($plan, 'execution_guard.dry_run'))->toBeTrue();
    Http::assertNothingSent();
});
