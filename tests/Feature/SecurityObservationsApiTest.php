<?php

use App\Models\SecurityObservation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
});

function securityObservationHeaders(User $user, Team $team, array $abilities = ['write']): array
{
    $token = $user->createToken('security-observation-test-token', $abilities, $team->id);

    return [
        'Authorization' => 'Bearer '.$token->plainTextToken,
        'Content-Type' => 'application/json',
    ];
}

test('write token can record a security observation without exposing source ip', function () {
    $response = $this->withHeaders(securityObservationHeaders($this->user, $this->team))
        ->postJson('/api/v1/security/observations', [
            'source_ip' => '203.0.113.10',
            'trace_id' => 'trace-abc123',
            'session_id' => 'session-def456',
            'layer' => 'L2',
            'signal' => 'path.probe.env',
            'score' => 50,
            'action' => 'block',
            'method' => 'GET',
            'path' => '/.env',
            'user_agent' => 'Mozilla/5.0',
            'metadata' => [
                'router' => 'coolify-edge-0-app123-probe-http',
            ],
        ]);

    $response->assertCreated()
        ->assertJsonStructure([
            'uuid',
            'source_hash',
            'trace_id',
            'session_id',
            'layer',
            'signal',
            'score',
            'action',
            'metadata',
            'source_score_15m',
            'observed_at',
            'created_at',
            'updated_at',
        ])
        ->assertJsonMissingPath('source_ip');

    expect(SecurityObservation::query()->where('team_id', $this->team->id)->where('signal', 'path.probe.env')->exists())
        ->toBeTrue();
});

test('read token cannot record a security observation', function () {
    $response = $this->withHeaders(securityObservationHeaders($this->user, $this->team, ['read']))
        ->postJson('/api/v1/security/observations', [
            'signal' => 'path.probe.env',
            'score' => 50,
        ]);

    $response->assertForbidden();
});

test('read token can list team security observations', function () {
    SecurityObservation::create([
        'team_id' => $this->team->id,
        'trace_id' => 'trace-abc123',
        'session_id' => 'session-def456',
        'source_ip' => '203.0.113.10',
        'source_hash' => hash('sha256', '203.0.113.10'),
        'layer' => 'L1',
        'signal' => 'fingerprint.suspicious_user_agent',
        'score' => 20,
        'action' => 'observe',
        'observed_at' => now(),
    ]);

    $response = $this->withHeaders(securityObservationHeaders($this->user, $this->team, ['read']))
        ->getJson('/api/v1/security/observations?trace_id=trace-abc123');

    $response->assertSuccessful()
        ->assertJsonFragment([
            'signal' => 'fingerprint.suspicious_user_agent',
            'trace_id' => 'trace-abc123',
        ])
        ->assertJsonMissingPath('0.source_ip');
});
