<?php

use App\Models\InstanceSettings;
use App\Models\InfraLedger;
use App\Models\Sl1Entity;
use App\Models\Sl1IdentityBinding;
use App\Models\Sl1IdentityEvent;
use App\Models\Sl1PeerNode;
use App\Models\SovereignAdminClaim;
use App\Models\User;
use App\Services\Sl1PeerRegistryService;
use App\Services\SovereignAdminClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
    config()->set('sovereign.sl1_connect.issuer', 'https://simplel1.online');
    config()->set('sovereign.sl1_connect.client_id', 'coolify.sovereign');
    config()->set('sovereign.sl1_connect.client_name', 'Sovereign Coolify');
    config()->set('sovereign.sl1_connect.embedded.enabled', true);
    config()->set('sovereign.sl1_connect.embedded.issuer_path', '/sl1');
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
    $this->assertDatabaseHas('sl1_entities', [
        'entity_address' => 'sl1e_root',
        'alias' => '@operator',
        'status' => 'active',
    ]);
    $this->assertDatabaseHas('sl1_identity_events', [
        'entity_address' => 'sl1e_root',
        'event_type' => 'sl1.identity.proof.observed',
        'source' => 'sl1-connect-callback',
    ]);
    expect(InstanceSettings::findOrFail(0)->is_registration_enabled)->toBeFalsy();
});

test('embedded sl1 runtime status exposes durable store counts', function () {
    Sl1Entity::create([
        'entity_address' => 'sl1e_status',
        'status' => 'active',
        'last_verified_at' => now(),
    ]);
    Sl1IdentityEvent::create([
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_status',
        'source' => 'test',
        'event_hash' => 'hash_status',
        'payload' => ['ok' => true],
        'occurred_at' => now(),
    ]);

    $this->getJson('/sl1/status')
        ->assertOk()
        ->assertJsonPath('protocol', 'simple-l1')
        ->assertJsonPath('mode', 'embedded')
        ->assertJsonPath('storage', 'coolify-postgres')
        ->assertJsonPath('entities', 1)
        ->assertJsonPath('events', 1);
});

test('sl1 peer registry verifies embedded runtime metadata without syncing authority', function () {
    Http::fake([
        'https://peer.example.test/sl1/status' => Http::response([
            'protocol' => 'simple-l1',
            'runtime' => 'coolify.embedded-sl1.runtime.v0',
            'mode' => 'embedded',
            'enabled' => true,
            'issuer' => 'https://peer.example.test/sl1',
            'storage' => 'coolify-postgres',
            'entities' => 1,
            'controllers' => 1,
            'events' => 3,
        ]),
        'https://peer.example.test/sl1/.well-known/issuer' => Http::response([
            'protocol' => 'simple-l1',
            'issuer' => 'https://peer.example.test/sl1',
            'runtime' => 'coolify.embedded-sl1.runtime.v0',
            'storage' => 'coolify-postgres',
            'capabilities' => [
                'durable_identity_store',
                'proof_projection',
                'coolify_backup_scope',
            ],
        ]),
    ]);

    $peer = app(Sl1PeerRegistryService::class)->register('peer.example.test', 'Peer Example');
    $result = app(Sl1PeerRegistryService::class)->verify($peer);

    expect($result['ok'])->toBeTrue()
        ->and($result['peer']->status)->toBe(Sl1PeerNode::STATUS_VERIFIED)
        ->and($result['peer']->issuer)->toBe('https://peer.example.test/sl1')
        ->and($result['peer']->runtime)->toBe('coolify.embedded-sl1.runtime.v0');
    expect(Sl1IdentityEvent::count())->toBe(0);
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

test('valid admin claim binds first sl1 identity to existing root admin', function () {
    InstanceSettings::findOrFail(0)->forceFill(['is_registration_enabled' => false])->save();
    $admin = User::factory()->create([
        'id' => 0,
        'name' => 'Classic Root',
        'email' => 'root@classic.test',
    ]);
    $claim = app(SovereignAdminClaimService::class)->createForUser($admin, 30);

    $this->get('/auth/sl1/admin-claim/'.$claim['token'])
        ->assertRedirect();

    $session = session('sl1_connect_login');
    expect($session['claim_token'])->toBe($claim['token'])
        ->and($session['flow'])->toBe('admin_claim');

    fakeSl1Http('sl1e_claimed_root', $session['nonce']);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect('/');

    $this->assertAuthenticatedAs($admin);
    expect(User::count())->toBe(1)
        ->and($admin->fresh()->teams->firstWhere('id', 0))->not->toBeNull();

    $this->assertDatabaseHas('sl1_identity_bindings', [
        'user_id' => 0,
        'entity_address' => 'sl1e_claimed_root',
    ]);
    expect(SovereignAdminClaim::first()->claimed_entity_address)->toBe('sl1e_claimed_root')
        ->and(InfraLedger::where('event_type', 'identity.admin.claimed')->count())->toBe(1);
});

test('admin claim token alone cannot authenticate', function () {
    $admin = User::factory()->create(['id' => 0]);
    $claim = app(SovereignAdminClaimService::class)->createForUser($admin, 30);

    $this->get('/auth/sl1/admin-claim/'.$claim['token'])
        ->assertRedirect();

    $this->assertGuest();
    $this->assertDatabaseMissing('sl1_identity_bindings', [
        'user_id' => 0,
    ]);
});

test('expired admin claim is rejected before sl1 redirect', function () {
    $admin = User::factory()->create(['id' => 0]);
    $claim = app(SovereignAdminClaimService::class)->createForUser($admin, 30);
    $claim['claim']->forceFill(['expires_at' => now()->subMinute()])->save();

    $this->get('/auth/sl1/admin-claim/'.$claim['token'])
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

test('admin claim rejects sl1 identity already bound to another user', function () {
    InstanceSettings::findOrFail(0)->forceFill(['is_registration_enabled' => false])->save();
    $admin = User::factory()->create(['id' => 0]);
    $other = User::factory()->create();
    Sl1IdentityBinding::create([
        'user_id' => $other->id,
        'entity_address' => 'sl1e_taken',
        'last_verified_at' => now(),
    ]);
    $claim = app(SovereignAdminClaimService::class)->createForUser($admin, 30);

    $this->get('/auth/sl1/admin-claim/'.$claim['token'])
        ->assertRedirect();

    $session = session('sl1_connect_login');
    fakeSl1Http('sl1e_taken', $session['nonce']);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect(route('login'));

    $this->assertGuest();
    $this->assertDatabaseMissing('sl1_identity_bindings', [
        'user_id' => 0,
        'entity_address' => 'sl1e_taken',
    ]);
    expect(SovereignAdminClaim::first()->claimed_at)->toBeNull();
});

test('admin claim command auto prints one time claim url for existing root admin', function () {
    User::factory()->create(['id' => 0]);

    $this->artisan('sovereign:admin-claim', [
        '--auto' => true,
        '--base-url' => 'http://coolify.test',
    ])
        ->expectsOutputToContain('CLAIM_URL=http://coolify.test/auth/sl1/admin-claim/')
        ->assertExitCode(0);

    expect(SovereignAdminClaim::count())->toBe(1);
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
