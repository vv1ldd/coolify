<?php

use App\Livewire\Security\CloudProviderTokens;
use App\Models\CloudProviderToken;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
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

test('saved token validation supports selectel provider tokens', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'selectel_vds',
        'token' => 'selectel-secret-token',
    ]);

    Http::fake([
        'https://api.vscale.io/v1/scalets' => Http::response([], 200),
    ]);

    Livewire::test(CloudProviderTokens::class)
        ->call('validateToken', $token->id)
        ->assertDispatched('success');

    Http::assertSent(fn ($request) => $request->hasHeader('X-Token', 'selectel-secret-token')
        && str_ends_with($request->url(), '/scalets'));
});

test('saved token validation supports hostinger provider tokens', function () {
    $token = CloudProviderToken::factory()->create([
        'team_id' => $this->team->id,
        'provider' => 'hostinger_vps',
        'token' => 'hostinger-secret-token',
    ]);

    Http::fake([
        'https://developers.hostinger.com/api/vps/v1/virtual-machines' => Http::response([], 200),
    ]);

    Livewire::test(CloudProviderTokens::class)
        ->call('validateToken', $token->id)
        ->assertDispatched('success');

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer hostinger-secret-token')
        && str_ends_with($request->url(), '/api/vps/v1/virtual-machines'));
});
