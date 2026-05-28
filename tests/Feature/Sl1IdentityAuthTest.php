<?php

use App\Models\InstanceSettings;
use App\Models\Sl1IdentityBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    config()->set('sovereign.sl1_connect.issuer', 'https://simplel1.online');
    config()->set('sovereign.sl1_connect.client_id', 'coolify.sovereign');
    config()->set('sovereign.sl1_connect.client_name', 'Sovereign Coolify');
});

function beginSl1Login($test): array
{
    $response = $test->get('/auth/sl1/redirect');
    $response->assertRedirect();

    $session = session('sl1_connect_login');
    expect($session)->toBeArray();

    return [$response, $session];
}

function fakeSl1Proof(string $entityAddress, string $nonce, array $overrides = []): array
{
    return array_replace([
        'object_type' => 'IdentityProof',
        'proof_id' => 'idp_test_'.substr(hash('sha256', $entityAddress.$nonce), 0, 16),
        'entity_l1_address' => $entityAddress,
        'controller_l1_address' => 'sl1_controller_test',
        'audience' => 'coolify.sovereign',
        'nonce' => $nonce,
        'alias' => '@operator',
        'display_alias' => '@operator',
        'displayName' => '@operator',
        'expires_at' => now()->addMinutes(10)->toIso8601String(),
    ], $overrides);
}

function fakeSl1Http(string $entityAddress, string $nonce, array $proofOverrides = [], int $introspectionStatus = 200): void
{
    $proof = fakeSl1Proof($entityAddress, $nonce, $proofOverrides);
    $payload = [
        'protocol' => 'simple-l1',
        'success' => true,
        'active' => true,
        'proof_token' => 'sl1p_test',
        'proof' => $proof,
        'identity' => [
            'entity_l1_address' => $entityAddress,
            'key_l1_address' => 'sl1_controller_test',
            'alias' => '@operator',
            'display_alias' => '@operator',
        ],
    ];

    Http::fake([
        'https://simplel1.online/api/sl1e/authorization-code/exchange' => Http::response($payload),
        'https://simplel1.online/api/sl1e/proofs/introspect' => Http::response($payload, $introspectionStatus),
    ]);
}

test('sl1 redirect sends user to connect and stores state', function () {
    [$response, $session] = beginSl1Login($this);

    $location = $response->headers->get('Location');

    expect($location)->toContain('https://simplel1.online/authorize')
        ->and($location)->toContain('client_id=coolify.sovereign')
        ->and($location)->toContain('flow=connect')
        ->and($session['state'])->not->toBeEmpty()
        ->and($session['nonce'])->not->toBeEmpty();
});

test('first verified sl1 identity creates root user and binding', function () {
    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_root', $session['nonce']);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect('/');

    $this->assertAuthenticated();
    $this->assertDatabaseHas('users', [
        'id' => 0,
        'name' => '@operator',
    ]);
    $this->assertDatabaseHas('sl1_identity_bindings', [
        'entity_address' => 'sl1e_root',
        'alias' => '@operator',
    ]);
    expect(InstanceSettings::findOrFail(0)->is_registration_enabled)->toBeFalsy();
});

test('existing sl1 identity logs into its bound user even when registration is disabled', function () {
    InstanceSettings::findOrFail(0)->forceFill(['is_registration_enabled' => false])->save();
    $user = User::factory()->create(['name' => 'Existing Operator']);
    Sl1IdentityBinding::create([
        'user_id' => $user->id,
        'entity_address' => 'sl1e_existing',
        'last_verified_at' => now(),
    ]);

    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_existing', $session['nonce']);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

test('new sl1 identity is denied when registration is disabled', function () {
    User::factory()->create();
    InstanceSettings::findOrFail(0)->forceFill(['is_registration_enabled' => false])->save();

    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_new', $session['nonce']);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('sl1_identity_bindings', [
        'entity_address' => 'sl1e_new',
    ]);
});

test('sl1 callback rejects state mismatch', function () {
    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_state', $session['nonce']);

    $this->get('/auth/sl1/callback?state=wrong-state&code=sl1c_test')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('sl1 callback rejects nonce mismatch', function () {
    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_nonce', 'different-nonce');

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('sl1 callback rejects failed introspection', function () {
    [, $session] = beginSl1Login($this);
    fakeSl1Http('sl1e_failed', $session['nonce'], [], 403);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect(route('login'));

    $this->assertGuest();
});
