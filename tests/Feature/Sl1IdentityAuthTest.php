<?php

use App\Models\InfraLedger;
use App\Models\InstanceSettings;
use App\Models\PolicyDecision;
use App\Models\Sl1Controller;
use App\Models\Sl1Entity;
use App\Models\Sl1IdentityBinding;
use App\Models\Sl1IdentityEvent;
use App\Models\Sl1NodeIdentity;
use App\Models\Sl1PeerIdentity;
use App\Models\Sl1PeerNode;
use App\Models\Sl1PeerObservedEvent;
use App\Models\Sl1PeerSyncCursor;
use App\Models\SovereignAdminClaim;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\PolicyEngine;
use App\Services\Sl1NodeIdentityService;
use App\Services\Sl1PeerRegistryService;
use App\Services\SovereignAdminClaimService;
use App\Services\TeamInvitationArtifactService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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

function sl1TestCanonicalValue(mixed $value): mixed
{
    if (! is_array($value)) {
        return $value;
    }

    if (array_is_list($value)) {
        return array_map(fn ($item) => sl1TestCanonicalValue($item), $value);
    }

    ksort($value);

    return array_map(fn ($item) => sl1TestCanonicalValue($item), $value);
}

function sl1TestEventHash(array $event): string
{
    return hash('sha256', json_encode(sl1TestCanonicalValue([
        'event_type' => $event['event_type'],
        'entity_address' => $event['entity_address'],
        'controller_address' => $event['controller_address'] ?? null,
        'proof_id' => $event['proof_id'] ?? null,
        'payload' => $event['payload'] ?? [],
    ]), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

function sl1TestSignEvent(array $event, string $issuer): array
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKeyEncoded = sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
    $nodeId = 'sl1node_'.substr(hash('sha256', $publicKeyEncoded), 0, 40);
    $signaturePayload = app(Sl1NodeIdentityService::class)->signaturePayload($event, $nodeId, $issuer);
    $signature = sodium_crypto_sign_detached(
        json_encode(sl1TestCanonicalValue($signaturePayload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        $secretKey
    );

    $event['node_signature'] = [
        'scope' => Sl1NodeIdentityService::SIGNATURE_SCOPE_IDENTITY_EVENT,
        'node_id' => $nodeId,
        'issuer' => $issuer,
        'algorithm' => 'ed25519',
        'signature' => sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
    ];

    return [$event, $nodeId, $publicKeyEncoded];
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

    expect($location)->toContain('https://simplel1.online/')
        ->and($location)->not->toContain('client_name=')
        ->and($location)->not->toContain('redirect_uri=')
        ->and(
            str_contains($location, '/r/sl1rq_')
            || str_contains($location, '/authorize/coolify.sovereign')
        )->toBeTrue()
        ->and($session['state'])->not->toBeEmpty()
        ->and($session['nonce'])->not->toBeEmpty();
});

test('sl1 redirect uses pushed authorize url when client secret is configured', function () {
    config()->set('sovereign.sl1_connect.client_secret', 'test-par-secret');

    Http::fake([
        'https://simplel1.online/api/sl1e/authorize/requests' => Http::response([
            'authorize_url' => 'https://simplel1.online/r/sl1rq_testref',
            'request_ref' => 'sl1rq_testref',
        ], 201),
    ]);

    [$response] = beginSl1Login($this);

    expect($response->headers->get('Location'))->toBe('https://simplel1.online/r/sl1rq_testref');
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

test('consented email claim is stored as binding contact attribute without becoming identity key', function () {
    [, $session] = beginSl1Login($this);

    $proof = fakeSl1Proof('sl1e_emailed', $session['nonce']);
    $payload = [
        'protocol' => 'simple-l1',
        'success' => true,
        'active' => true,
        'proof_token' => 'sl1p_test',
        'proof' => $proof,
        'identity' => [
            'entity_l1_address' => 'sl1e_emailed',
            'key_l1_address' => 'sl1_controller_test',
            'alias' => '@operator',
            'display_alias' => '@operator',
            'email' => 'operator@example.com',
            'email_hash' => 'sha256:'.hash('sha256', 'operator@example.com'),
        ],
    ];

    Http::fake([
        'https://simplel1.online/api/sl1e/authorization-code/exchange' => Http::response($payload),
        'https://simplel1.online/api/sl1e/proofs/introspect' => Http::response($payload),
    ]);

    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_test')
        ->assertRedirect('/');

    $this->assertAuthenticated();

    $binding = Sl1IdentityBinding::where('entity_address', 'sl1e_emailed')->firstOrFail();
    expect($binding->contact_email)->toBe('operator@example.com')
        ->and($binding->contact_email_hash)->toBe('sha256:'.hash('sha256', 'operator@example.com'));

    // Email is a non-authoritative contact claim: identity key stays the entity address,
    // and the user's login email remains the synthetic projection, not the real email.
    $user = $binding->user;
    expect($user->email)->not->toBe('operator@example.com')
        ->and($user->email)->toContain('@identity.sl1.local');
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
        ->assertJsonPath('protocol_version', 'capsule-v0')
        ->assertJsonPath('storage', 'coolify-postgres')
        ->assertJsonPath('storage_role', 'cache')
        ->assertJsonPath('identity_authority', 'identity_capsule+state_proof+webauthn_assertion')
        ->assertJsonPath('identity_capsules_enabled', true)
        ->assertJsonPath('default_assurance_level', 'AL1')
        ->assertJsonPath('resolvers.evidence.1', 'client-capsule')
        ->assertJsonPath('entities', 1)
        ->assertJsonPath('events', 1);
});

test('embedded sl1 runtime exposes identity events as non-authoritative stream', function () {
    Sl1Entity::create([
        'entity_address' => 'sl1e_stream',
        'status' => 'active',
        'last_verified_at' => now(),
    ]);
    Sl1IdentityEvent::create([
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_stream',
        'source' => 'test',
        'event_hash' => 'hash_stream',
        'payload' => ['proof' => ['proof_id' => 'proof_stream']],
        'occurred_at' => now(),
    ]);

    $this->getJson('/sl1/events')
        ->assertOk()
        ->assertJsonPath('protocol', 'simple-l1')
        ->assertJsonPath('stream', 'identity_events')
        ->assertJsonPath('authoritative', false)
        ->assertJsonPath('events.0.event_hash', 'hash_stream')
        ->assertJsonPath('events.0.node_signature.scope', Sl1NodeIdentityService::SIGNATURE_SCOPE_IDENTITY_EVENT)
        ->assertJsonPath('events.0.node_signature.algorithm', 'ed25519');
});

test('embedded sl1 issuer exposes durable node identity metadata', function () {
    config()->set('app.url', 'https://host.example.test');

    $this->getJson('/sl1/.well-known/issuer')
        ->assertOk()
        ->assertJsonPath('node_identity.issuer', 'https://host.example.test/sl1')
        ->assertJsonPath('node_identity.signature_algorithm', 'ed25519')
        ->assertJsonPath('protocol_version', 'capsule-v0')
        ->assertJsonPath('storage_role', 'cache')
        ->assertJsonPath('identity_authority', 'identity_capsule+state_proof+webauthn_assertion')
        ->assertJsonPath('capabilities.3', 'node_identity_discovery')
        ->assertJsonPath('capabilities.4', 'identity_capsule_provenance')
        ->assertJsonPath('capabilities.5', 'state_proof_freshness')
        ->assertJsonPath('capabilities.6', 'assurance_level_reporting');

    expect(Sl1NodeIdentity::count())->toBe(1);

    $nodeId = Sl1NodeIdentity::first()->node_id;
    $this->getJson('/sl1/.well-known/issuer')
        ->assertOk()
        ->assertJsonPath('node_identity.node_id', $nodeId);
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
            'storage_role' => 'cache',
            'identity_authority' => 'identity_capsule+state_proof+webauthn_assertion',
            'entities' => 1,
            'controllers' => 1,
            'events' => 3,
        ]),
        'https://peer.example.test/sl1/.well-known/issuer' => Http::response([
            'protocol' => 'simple-l1',
            'issuer' => 'https://peer.example.test/sl1',
            'runtime' => 'coolify.embedded-sl1.runtime.v0',
            'storage' => 'coolify-postgres',
            'storage_role' => 'cache',
            'identity_authority' => 'identity_capsule+state_proof+webauthn_assertion',
            'node_identity' => [
                'node_id' => 'sl1node_peer_test',
                'issuer' => 'https://peer.example.test/sl1',
                'signature_algorithm' => 'ed25519',
                'public_key' => 'peer_public_key_test',
                'status' => 'active',
            ],
            'capabilities' => [
                'durable_identity_store',
                'proof_projection',
                'coolify_backup_scope',
                'node_identity_discovery',
                'identity_capsule_provenance',
                'state_proof_freshness',
                'assurance_level_reporting',
            ],
        ]),
    ]);

    $peer = app(Sl1PeerRegistryService::class)->register('peer.example.test', 'Peer Example');
    $result = app(Sl1PeerRegistryService::class)->verify($peer);

    expect($result['ok'])->toBeTrue()
        ->and($result['peer']->status)->toBe(Sl1PeerNode::STATUS_VERIFIED)
        ->and($result['peer']->issuer)->toBe('https://peer.example.test/sl1')
        ->and($result['peer']->runtime)->toBe('coolify.embedded-sl1.runtime.v0');
    expect(Sl1IdentityEvent::count())->toBe(0)
        ->and(Sl1PeerIdentity::count())->toBe(1)
        ->and(Sl1PeerIdentity::first()->peer_node_id)->toBe('sl1node_peer_test');
});

test('sl1 peer sync stores remote events as candidate evidence only', function () {
    $remoteEvent = [
        'id' => '7',
        'uuid' => '11111111-1111-4111-8111-111111111111',
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_remote',
        'controller_address' => 'sl1_remote_controller',
        'proof_id' => 'remote_proof',
        'source' => 'remote-test',
        'previous_event_hash' => null,
        'payload' => ['proof' => ['proof_id' => 'remote_proof']],
        'occurred_at' => now()->toIso8601String(),
    ];
    $remoteEvent['event_hash'] = sl1TestEventHash($remoteEvent);

    Http::fake([
        'https://peer.example.test/sl1/events*' => Http::response([
            'protocol' => 'simple-l1',
            'runtime' => 'coolify.embedded-sl1.runtime.v0',
            'mode' => 'embedded',
            'issuer' => 'https://peer.example.test/sl1',
            'stream' => 'identity_events',
            'cursor' => '0',
            'next_cursor' => '7',
            'authoritative' => false,
            'events' => [$remoteEvent],
        ]),
    ]);

    $peer = Sl1PeerNode::create([
        'issuer' => 'https://peer.example.test/sl1',
        'name' => 'Peer Example',
        'status' => Sl1PeerNode::STATUS_VERIFIED,
    ]);

    $this->artisan('sl1:peer-sync', ['--peer-id' => $peer->id])
        ->expectsOutputToContain('authority_projection=unchanged')
        ->assertExitCode(0);

    expect(Sl1PeerObservedEvent::count())->toBe(1)
        ->and(Sl1PeerObservedEvent::first()->admissibility_status)->toBe(Sl1PeerObservedEvent::STATUS_CANDIDATE)
        ->and(Sl1PeerSyncCursor::first()->remote_cursor)->toBe('7')
        ->and(Sl1Entity::where('entity_address', 'sl1e_remote')->exists())->toBeFalse();
});

test('sl1 authority admissibility dry-run evaluates evidence without projection mutation', function () {
    $peer = Sl1PeerNode::create([
        'issuer' => 'https://peer.example.test/sl1',
        'name' => 'Peer Example',
        'status' => Sl1PeerNode::STATUS_VERIFIED,
    ]);
    $remoteEvent = [
        'id' => '8',
        'uuid' => '22222222-2222-4222-8222-222222222222',
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_remote_dry_run',
        'controller_address' => 'sl1_remote_controller',
        'proof_id' => 'remote_proof_dry_run',
        'source' => 'remote-test',
        'previous_event_hash' => null,
        'payload' => [
            'proof' => [
                'proof_id' => 'remote_proof_dry_run',
                'entity_l1_address' => 'sl1e_remote_dry_run',
                'controller_l1_address' => 'sl1_remote_controller',
            ],
        ],
        'occurred_at' => now()->toIso8601String(),
    ];
    $remoteEvent['event_hash'] = sl1TestEventHash($remoteEvent);
    [$remoteEvent, $nodeId, $publicKey] = sl1TestSignEvent($remoteEvent, 'https://peer.example.test/sl1');
    Sl1PeerIdentity::create([
        'sl1_peer_node_id' => $peer->id,
        'peer_node_id' => $nodeId,
        'signature_algorithm' => 'ed25519',
        'peer_public_key' => $publicKey,
        'trust_state' => Sl1PeerIdentity::TRUST_OBSERVED,
        'metadata' => ['issuer' => 'https://peer.example.test/sl1'],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);

    Sl1PeerObservedEvent::create([
        'sl1_peer_node_id' => $peer->id,
        'remote_event_id' => $remoteEvent['id'],
        'remote_event_uuid' => $remoteEvent['uuid'],
        'remote_event_hash' => $remoteEvent['event_hash'],
        'event_type' => $remoteEvent['event_type'],
        'entity_address' => $remoteEvent['entity_address'],
        'controller_address' => $remoteEvent['controller_address'],
        'source' => $remoteEvent['source'],
        'remote_payload' => $remoteEvent['payload'],
        'remote_envelope' => $remoteEvent,
        'admissibility_status' => Sl1PeerObservedEvent::STATUS_CANDIDATE,
        'occurred_at' => $remoteEvent['occurred_at'],
        'observed_at' => now(),
    ]);

    $this->artisan('sl1:authority-admissibility-dry-run', ['--peer-id' => $peer->id])
        ->expectsOutputToContain('authority_projection=unchanged')
        ->assertExitCode(0);

    $exitCode = Artisan::call('sl1:authority-admissibility-dry-run', [
        '--peer-id' => $peer->id,
        '--json' => true,
    ]);
    $jsonReport = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($jsonReport['schema_version'])->toBe('sovereign.runtime.authority_admissibility_dry_run.v1')
        ->and($jsonReport['authority_projection'])->toBe('unchanged')
        ->and($jsonReport['events'][0]['evaluation_contexts']['crypto_context']['signature_valid'])->toBeTrue()
        ->and($jsonReport['events'][0]['policy_context']['policy_mode'])->toBe('observe_only')
        ->and($jsonReport['events'][0]['projection_candidate']['commit_blockers'])->toContain('observe_only_policy');

    $observed = Sl1PeerObservedEvent::first();
    expect($observed->admissibility_status)->toBe(Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE)
        ->and($observed->admissibility_report['authority_projection'])->toBe('unchanged')
        ->and($observed->admissibility_report['crypto_context']['signer_known'])->toBeTrue()
        ->and($observed->admissibility_report['crypto_context']['signature_valid'])->toBeTrue()
        ->and($observed->admissibility_report['binding_context']['controller_binding_valid'])->toBeTrue()
        ->and($observed->admissibility_report['policy_context']['policy_mode'])->toBe('observe_only')
        ->and($observed->admissibility_report['policy_context']['projection_allowed'])->toBeFalse()
        ->and($observed->admissibility_report['policy_context']['projection_stage'])->toBe('causal_relation_unresolved')
        ->and($observed->admissibility_report['projection_candidate']['would_project'])->toBeFalse()
        ->and($observed->admissibility_report['projection_candidate']['projection_blockers'])->toContain('causal_relation_unresolved')
        ->and($observed->admissibility_report['projection_candidate']['commit_blockers'])->toContain('observe_only_policy')
        ->and(Sl1Entity::where('entity_address', 'sl1e_remote_dry_run')->exists())->toBeFalse();
});

test('sl1 authority dry-run can compute shadow projection candidate without committing', function () {
    $peer = Sl1PeerNode::create([
        'issuer' => 'https://peer.example.test/sl1',
        'name' => 'Peer Example',
        'status' => Sl1PeerNode::STATUS_VERIFIED,
    ]);
    Sl1Entity::create([
        'entity_address' => 'sl1e_shadow_ready',
        'status' => 'active',
        'current_event_hash' => 'local_tip_hash',
        'last_verified_at' => now(),
    ]);
    Sl1Controller::create([
        'entity_address' => 'sl1e_shadow_ready',
        'controller_address' => 'sl1_shadow_controller',
        'status' => 'active',
        'added_at' => now(),
    ]);
    $remoteEvent = [
        'id' => '11',
        'uuid' => '55555555-5555-4555-8555-555555555555',
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_shadow_ready',
        'controller_address' => 'sl1_shadow_controller',
        'proof_id' => 'remote_proof_shadow',
        'source' => 'remote-test',
        'previous_event_hash' => 'local_tip_hash',
        'payload' => [
            'proof' => [
                'proof_id' => 'remote_proof_shadow',
                'entity_l1_address' => 'sl1e_shadow_ready',
                'controller_l1_address' => 'sl1_shadow_controller',
            ],
        ],
        'occurred_at' => now()->toIso8601String(),
    ];
    $remoteEvent['event_hash'] = sl1TestEventHash($remoteEvent);
    [$remoteEvent, $nodeId, $publicKey] = sl1TestSignEvent($remoteEvent, 'https://peer.example.test/sl1');
    Sl1PeerIdentity::create([
        'sl1_peer_node_id' => $peer->id,
        'peer_node_id' => $nodeId,
        'signature_algorithm' => 'ed25519',
        'peer_public_key' => $publicKey,
        'trust_state' => Sl1PeerIdentity::TRUST_OBSERVED,
        'metadata' => ['issuer' => 'https://peer.example.test/sl1'],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    Sl1PeerObservedEvent::create([
        'sl1_peer_node_id' => $peer->id,
        'remote_event_id' => $remoteEvent['id'],
        'remote_event_uuid' => $remoteEvent['uuid'],
        'remote_event_hash' => $remoteEvent['event_hash'],
        'event_type' => $remoteEvent['event_type'],
        'entity_address' => $remoteEvent['entity_address'],
        'controller_address' => $remoteEvent['controller_address'],
        'source' => $remoteEvent['source'],
        'remote_payload' => $remoteEvent['payload'],
        'remote_envelope' => $remoteEvent,
        'admissibility_status' => Sl1PeerObservedEvent::STATUS_CANDIDATE,
        'occurred_at' => $remoteEvent['occurred_at'],
        'observed_at' => now(),
    ]);

    $this->artisan('sl1:authority-admissibility-dry-run', ['--peer-id' => $peer->id])
        ->expectsOutputToContain('authority_projection=unchanged')
        ->assertExitCode(0);

    $observed = Sl1PeerObservedEvent::first();
    expect($observed->admissibility_report['policy_context']['projection_stage'])->toBe('projection_candidate_shadow_only')
        ->and($observed->admissibility_report['projection_candidate']['would_project'])->toBeTrue()
        ->and($observed->admissibility_report['projection_candidate']['projection_confidence'])->toBe('high')
        ->and($observed->admissibility_report['projection_candidate']['projection_blockers'])->toBe([])
        ->and($observed->admissibility_report['projection_candidate']['commit_blockers'])->toBe(['observe_only_policy'])
        ->and(Sl1Entity::where('entity_address', 'sl1e_shadow_ready')->first()->current_event_hash)->toBe('local_tip_hash');
});

test('sl1 authority admissibility reports invalid signature without rejecting structural evidence', function () {
    $peer = Sl1PeerNode::create([
        'issuer' => 'https://peer.example.test/sl1',
        'name' => 'Peer Example',
        'status' => Sl1PeerNode::STATUS_VERIFIED,
    ]);
    $remoteEvent = [
        'id' => '9',
        'uuid' => '33333333-3333-4333-8333-333333333333',
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_remote_bad_signature',
        'controller_address' => 'sl1_remote_controller',
        'proof_id' => 'remote_proof_bad_signature',
        'source' => 'remote-test',
        'previous_event_hash' => null,
        'payload' => ['proof' => ['proof_id' => 'remote_proof_bad_signature']],
        'occurred_at' => now()->toIso8601String(),
    ];
    $remoteEvent['event_hash'] = sl1TestEventHash($remoteEvent);
    [$remoteEvent, $nodeId, $publicKey] = sl1TestSignEvent($remoteEvent, 'https://peer.example.test/sl1');
    $remoteEvent['node_signature']['signature'] = strrev($remoteEvent['node_signature']['signature']);

    Sl1PeerIdentity::create([
        'sl1_peer_node_id' => $peer->id,
        'peer_node_id' => $nodeId,
        'signature_algorithm' => 'ed25519',
        'peer_public_key' => $publicKey,
        'trust_state' => Sl1PeerIdentity::TRUST_OBSERVED,
        'metadata' => ['issuer' => 'https://peer.example.test/sl1'],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    Sl1PeerObservedEvent::create([
        'sl1_peer_node_id' => $peer->id,
        'remote_event_id' => $remoteEvent['id'],
        'remote_event_uuid' => $remoteEvent['uuid'],
        'remote_event_hash' => $remoteEvent['event_hash'],
        'event_type' => $remoteEvent['event_type'],
        'entity_address' => $remoteEvent['entity_address'],
        'controller_address' => $remoteEvent['controller_address'],
        'source' => $remoteEvent['source'],
        'remote_payload' => $remoteEvent['payload'],
        'remote_envelope' => $remoteEvent,
        'admissibility_status' => Sl1PeerObservedEvent::STATUS_CANDIDATE,
        'occurred_at' => $remoteEvent['occurred_at'],
        'observed_at' => now(),
    ]);

    $this->artisan('sl1:authority-admissibility-dry-run', ['--peer-id' => $peer->id])
        ->expectsOutputToContain('authority_projection=unchanged')
        ->assertExitCode(0);

    $observed = Sl1PeerObservedEvent::first();
    expect($observed->admissibility_status)->toBe(Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE)
        ->and($observed->admissibility_report['crypto_context']['signer_known'])->toBeTrue()
        ->and($observed->admissibility_report['crypto_context']['signature_valid'])->toBeFalse()
        ->and($observed->admissibility_report['policy_context']['projection_stage'])->toBe('crypto_failed')
        ->and($observed->admissibility_report['policy_context']['projection_allowed'])->toBeFalse()
        ->and(Sl1Entity::where('entity_address', 'sl1e_remote_bad_signature')->exists())->toBeFalse();
});

test('sl1 authority admissibility reports invalid controller binding without projection mutation', function () {
    $peer = Sl1PeerNode::create([
        'issuer' => 'https://peer.example.test/sl1',
        'name' => 'Peer Example',
        'status' => Sl1PeerNode::STATUS_VERIFIED,
    ]);
    $remoteEvent = [
        'id' => '10',
        'uuid' => '44444444-4444-4444-8444-444444444444',
        'event_type' => 'sl1.identity.proof.observed',
        'entity_address' => 'sl1e_remote_binding',
        'controller_address' => 'sl1_remote_controller',
        'proof_id' => 'remote_proof_binding',
        'source' => 'remote-test',
        'previous_event_hash' => null,
        'payload' => [
            'proof' => [
                'proof_id' => 'remote_proof_binding',
                'entity_l1_address' => 'sl1e_remote_binding',
                'controller_l1_address' => 'sl1_different_controller',
            ],
        ],
        'occurred_at' => now()->toIso8601String(),
    ];
    $remoteEvent['event_hash'] = sl1TestEventHash($remoteEvent);
    [$remoteEvent, $nodeId, $publicKey] = sl1TestSignEvent($remoteEvent, 'https://peer.example.test/sl1');

    Sl1PeerIdentity::create([
        'sl1_peer_node_id' => $peer->id,
        'peer_node_id' => $nodeId,
        'signature_algorithm' => 'ed25519',
        'peer_public_key' => $publicKey,
        'trust_state' => Sl1PeerIdentity::TRUST_OBSERVED,
        'metadata' => ['issuer' => 'https://peer.example.test/sl1'],
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ]);
    Sl1PeerObservedEvent::create([
        'sl1_peer_node_id' => $peer->id,
        'remote_event_id' => $remoteEvent['id'],
        'remote_event_uuid' => $remoteEvent['uuid'],
        'remote_event_hash' => $remoteEvent['event_hash'],
        'event_type' => $remoteEvent['event_type'],
        'entity_address' => $remoteEvent['entity_address'],
        'controller_address' => $remoteEvent['controller_address'],
        'source' => $remoteEvent['source'],
        'remote_payload' => $remoteEvent['payload'],
        'remote_envelope' => $remoteEvent,
        'admissibility_status' => Sl1PeerObservedEvent::STATUS_CANDIDATE,
        'occurred_at' => $remoteEvent['occurred_at'],
        'observed_at' => now(),
    ]);

    $this->artisan('sl1:authority-admissibility-dry-run', ['--peer-id' => $peer->id])
        ->expectsOutputToContain('authority_projection=unchanged')
        ->assertExitCode(0);

    $observed = Sl1PeerObservedEvent::first();
    expect($observed->admissibility_status)->toBe(Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE)
        ->and($observed->admissibility_report['crypto_context']['signature_valid'])->toBeTrue()
        ->and($observed->admissibility_report['binding_context']['event_entity_matches_proof'])->toBeTrue()
        ->and($observed->admissibility_report['binding_context']['event_controller_matches_proof'])->toBeFalse()
        ->and($observed->admissibility_report['binding_context']['controller_binding_valid'])->toBeFalse()
        ->and($observed->admissibility_report['policy_context']['projection_stage'])->toBe('binding_failed')
        ->and($observed->admissibility_report['policy_context']['projection_allowed'])->toBeFalse()
        ->and(Sl1Entity::where('entity_address', 'sl1e_remote_binding')->exists())->toBeFalse();
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

test('signed team invitation admits new sl1 identity when registration is disabled', function () {
    InstanceSettings::findOrFail(0)->forceFill(['is_registration_enabled' => false])->save();
    $team = Team::factory()->create(['name' => 'Operators']);
    $owner = User::factory()->create(['email' => 'owner@example.com']);
    $team->members()->attach($owner->id, ['role' => 'owner']);
    $decision = PolicyDecision::create(app(PolicyEngine::class)->evaluateTeamMemberInvite(
        issuer: $owner,
        team: $team,
        requestedRole: 'admin',
        deliveryEmail: 'candidate@example.com',
    ));
    $artifact = app(TeamInvitationArtifactService::class)->issueFromDecision($decision);
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => 'sl1invite_test',
        'email' => 'candidate@example.com',
        'role' => 'admin',
        'link' => route('auth.sl1.invitation', ['uuid' => 'sl1invite_test']),
        'via' => 'link',
        'artifact_version' => TeamInvitationArtifactService::ARTIFACT_VERSION,
        'team_invitation_artifact_id' => $artifact->id,
    ]);

    $this->get(route('auth.sl1.invitation', ['uuid' => $invitation->uuid]))
        ->assertRedirect();
    $session = session('sl1_connect_login');
    expect($session['flow'])->toBe('team_invitation')
        ->and($session['invitation_uuid'])->toBe($invitation->uuid);

    fakeSl1Http('sl1e_invited', $session['nonce']);
    $this->get('/auth/sl1/callback?state='.$session['state'].'&code=sl1c_invited')
        ->assertRedirect('/');

    $user = User::whereEmail('candidate@example.com')->firstOrFail();
    expect($team->members()->where('users.id', $user->id)->first()?->pivot?->role)->toBe('admin')
        ->and($artifact->refresh()->status)->toBe('consumed')
        ->and($artifact->consumed_by_entity_address)->toBe('sl1e_invited')
        ->and(TeamInvitation::whereKey($invitation->id)->exists())->toBeFalse();
    $this->assertAuthenticatedAs($user);
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
