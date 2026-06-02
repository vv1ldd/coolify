<?php

use App\Models\ControlPlanePeer;
use App\Models\ControlPlaneSnapshot;
use App\Models\DnsRecord;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function signedControlPlanePost(ControlPlanePeer $peer, array $payload, string $secret, ?string $signature = null)
{
    $timestamp = now()->toISOString();
    $body = json_encode($payload);
    $signature ??= 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret);

    return test()->call('POST', '/api/v1/control-plane/sync/snapshot', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_CONTROL_PLANE_PEER' => $peer->uuid,
        'HTTP_X_CONTROL_PLANE_TIMESTAMP' => $timestamp,
        'HTTP_X_CONTROL_PLANE_SIGNATURE' => $signature,
    ], $body);
}

function controlPlanePeerForTeam(Team $team, string $secret): ControlPlanePeer
{
    return ControlPlanePeer::create([
        'team_id' => $team->id,
        'name' => 'edge-sentinel-a',
        'region' => 'ams1',
        'role' => ControlPlanePeer::ROLE_EDGE_AGENT,
        'shared_secret' => $secret,
        'capabilities' => ['heartbeat', 'snapshot'],
    ]);
}

test('heartbeat endpoint accepts valid signed snapshot and updates peer status', function () {
    $team = Team::factory()->create();
    $secret = Str::random(48);
    $peer = controlPlanePeerForTeam($team, $secret);

    $payload = [
        'peer_uuid' => $peer->uuid,
        'name' => 'edge-sentinel-a',
        'public_ip' => '203.0.113.10',
        'region' => 'ams1',
        'role' => ControlPlanePeer::ROLE_EDGE_AGENT,
        'snapshot' => [
            'observed_at' => now()->toISOString(),
            'servers_summary' => [
                'total' => 1,
                'healthy' => 1,
                'nodes' => [
                    [
                        'server_uuid' => 'server-node-1',
                        'public_ip' => '203.0.113.20',
                        'healthy' => true,
                    ],
                ],
            ],
            'dns_steering_readiness' => ['status' => 'ready'],
            'edge_policy_versions' => ['policies' => 0],
            'regional_readiness_summary' => ['status' => 'ready'],
        ],
    ];

    $response = signedControlPlanePost($peer, $payload, $secret);

    $response->assertCreated()
        ->assertJsonFragment([
            'message' => 'ok',
        ]);

    $peer->refresh();
    expect($peer->status)->toBe(ControlPlanePeer::STATUS_ONLINE)
        ->and($peer->last_seen_at)->not->toBeNull()
        ->and(ControlPlaneSnapshot::query()->where('control_plane_peer_id', $peer->id)->count())->toBe(1)
        ->and(data_get(ControlPlaneSnapshot::first()->servers_summary, 'nodes.0.healthy'))->toBeTrue();
});

test('heartbeat endpoint rejects invalid signature', function () {
    $team = Team::factory()->create();
    $secret = Str::random(48);
    $peer = controlPlanePeerForTeam($team, $secret);

    $response = signedControlPlanePost($peer, [
        'peer_uuid' => $peer->uuid,
        'snapshot' => [
            'observed_at' => now()->toISOString(),
            'servers_summary' => ['total' => 0, 'nodes' => []],
        ],
    ], $secret, 'sha256=invalid');

    $response->assertUnauthorized();

    expect(ControlPlaneSnapshot::count())->toBe(0)
        ->and($peer->refresh()->last_seen_at)->toBeNull();
});

test('peer stale status is computed from last seen timestamp', function () {
    $team = Team::factory()->create();
    $peer = ControlPlanePeer::create([
        'team_id' => $team->id,
        'name' => 'edge-sentinel-stale',
        'status' => ControlPlanePeer::STATUS_ONLINE,
        'last_seen_at' => now()->subMinutes(10),
    ]);

    expect($peer->freshnessStatus(staleAfterMinutes: 5))->toBe(ControlPlanePeer::STATUS_STALE);
});

test('snapshot stores node health metadata without dns or provider mutations', function () {
    Http::preventStrayRequests();

    $team = Team::factory()->create();
    $secret = Str::random(48);
    $peer = controlPlanePeerForTeam($team, $secret);
    $recordsBefore = DnsRecord::count();

    $response = signedControlPlanePost($peer, [
        'peer_uuid' => $peer->uuid,
        'snapshot' => [
            'observed_at' => now()->toISOString(),
            'servers_summary' => [
                'total' => 2,
                'healthy' => 1,
                'nodes' => [
                    ['server_uuid' => 'primary-node', 'healthy' => false, 'reason' => 'unreachable'],
                    ['server_uuid' => 'secondary-node', 'healthy' => true],
                ],
            ],
            'metadata' => [
                'incident_id' => 'dry-run-only',
            ],
        ],
    ], $secret);

    $response->assertCreated();

    $snapshot = ControlPlaneSnapshot::first();
    expect(DnsRecord::count())->toBe($recordsBefore)
        ->and(data_get($snapshot->servers_summary, 'nodes.0.reason'))->toBe('unreachable')
        ->and(data_get($snapshot->metadata, 'incident_id'))->toBe('dry-run-only');
});
