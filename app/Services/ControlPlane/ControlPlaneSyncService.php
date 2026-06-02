<?php

namespace App\Services\ControlPlane;

use App\Models\ControlPlanePeer;
use App\Models\ControlPlaneSnapshot;
use App\Models\DnsSteeringPolicy;
use App\Models\EdgePolicy;
use App\Models\Server;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class ControlPlaneSyncService
{
    public const SIGNATURE_HEADER = 'X-Control-Plane-Signature';

    public const PEER_HEADER = 'X-Control-Plane-Peer';

    public const TIMESTAMP_HEADER = 'X-Control-Plane-Timestamp';

    public function buildLocalSnapshot(?int $teamId = null): array
    {
        $servers = Server::query()
            ->with('settings')
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->get();

        $nodes = $servers->map(function (Server $server): array {
            $healthy = $this->serverHealthy($server);

            return [
                'server_uuid' => $server->uuid,
                'name' => $server->name,
                'public_ip' => $server->ip,
                'healthy' => $healthy,
                'health_source' => 'server_settings',
                'signals' => [
                    'is_reachable' => (bool) data_get($server, 'settings.is_reachable'),
                    'is_usable' => (bool) data_get($server, 'settings.is_usable'),
                    'force_disabled' => (bool) data_get($server, 'settings.force_disabled'),
                    'is_build_server' => (bool) data_get($server, 'settings.is_build_server'),
                ],
            ];
        })->values();

        return [
            'observed_at' => now()->toISOString(),
            'servers_summary' => [
                'total' => $nodes->count(),
                'healthy' => $nodes->where('healthy', true)->count(),
                'unhealthy' => $nodes->where('healthy', false)->count(),
                'nodes' => $nodes->all(),
            ],
            'dns_steering_readiness' => $this->dnsSteeringReadiness($teamId),
            'edge_policy_versions' => $this->edgePolicyVersions($teamId),
            'regional_readiness_summary' => [
                'available' => false,
                'status' => 'not_configured',
                'source' => 'control_plane_sync_foundation',
            ],
            'metadata' => [
                'schema' => 'coolify.control_plane.snapshot.v1',
                'consistency' => 'eventual_observation',
                'dns_mutations' => 'not_performed',
                'provider_mutations' => 'not_performed',
            ],
        ];
    }

    public function ingestSignedSnapshot(Request $request, array $payload): array
    {
        $peerUuid = (string) ($request->header(self::PEER_HEADER) ?: data_get($payload, 'peer_uuid', ''));
        $peer = filled($peerUuid)
            ? ControlPlanePeer::query()->where('uuid', $peerUuid)->first()
            : null;

        if (! $peer || ! $this->validSignature($peer, $request->getContent(), $request->header(self::TIMESTAMP_HEADER), $request->header(self::SIGNATURE_HEADER))) {
            return [
                'ok' => false,
                'status' => 401,
                'message' => 'Invalid control plane signature.',
            ];
        }

        $snapshotPayload = $this->snapshotPayload($payload);
        $observedAt = $this->parseObservedAt(data_get($snapshotPayload, 'observed_at'));
        $snapshotMetadata = data_get($snapshotPayload, 'metadata', []);
        if (! is_array($snapshotMetadata)) {
            $snapshotMetadata = [];
        }

        $peer->forceFill(array_filter([
            'name' => data_get($payload, 'name'),
            'endpoint_url' => data_get($payload, 'endpoint_url'),
            'public_ip' => data_get($payload, 'public_ip'),
            'region' => data_get($payload, 'region'),
            'role' => data_get($payload, 'role'),
            'capabilities' => data_get($payload, 'capabilities'),
            'metadata' => data_get($payload, 'metadata'),
            'last_seen_at' => now(),
            'status' => ControlPlanePeer::STATUS_ONLINE,
        ], fn (mixed $value): bool => ! is_null($value)))->save();

        $snapshot = $peer->snapshots()->create([
            'observed_at' => $observedAt,
            'servers_summary' => data_get($snapshotPayload, 'servers_summary', data_get($snapshotPayload, 'servers', [])),
            'dns_steering_readiness' => data_get($snapshotPayload, 'dns_steering_readiness', []),
            'edge_policy_versions' => data_get($snapshotPayload, 'edge_policy_versions', []),
            'regional_readiness_summary' => data_get($snapshotPayload, 'regional_readiness_summary', data_get($snapshotPayload, 'regional_readiness', [])),
            'metadata' => array_merge(
                ['ingested_by' => 'control_plane_sync'],
                $snapshotMetadata,
            ),
        ]);

        return [
            'ok' => true,
            'peer' => $peer->refresh(),
            'snapshot' => $snapshot,
        ];
    }

    public function publicPeerStatus(ControlPlanePeer $peer): array
    {
        return [
            'uuid' => $peer->uuid,
            'name' => $peer->name,
            'endpoint_url' => $peer->endpoint_url,
            'public_ip' => $peer->public_ip,
            'region' => $peer->region,
            'role' => $peer->role,
            'status' => $peer->status,
            'effective_status' => $peer->freshnessStatus(),
            'last_seen_at' => $peer->last_seen_at?->toISOString(),
            'capabilities' => $peer->capabilities ?: [],
            'metadata' => $peer->metadata ?: [],
            'snapshots_count' => $peer->snapshots_count ?? null,
            'updated_at' => $peer->updated_at?->toISOString(),
        ];
    }

    public function signPayload(string $body, string $secret, ?string $timestamp = null): array
    {
        $timestamp ??= now()->toISOString();

        return [
            self::TIMESTAMP_HEADER => $timestamp,
            self::SIGNATURE_HEADER => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret),
        ];
    }

    public function publishSnapshotToPeer(ControlPlanePeer $peer, array $snapshot, bool $dryRun = true): array
    {
        $senderUuid = (string) data_get($peer->metadata, 'local_peer_uuid', '');
        $body = json_encode([
            'peer_uuid' => $senderUuid,
            'snapshot' => $snapshot,
        ], JSON_UNESCAPED_SLASHES);

        $plan = [
            'peer_uuid' => $peer->uuid,
            'endpoint_url' => $peer->endpoint_url,
            'dry_run' => $dryRun,
            'ready' => filled($peer->endpoint_url) && filled($peer->shared_secret) && filled($senderUuid),
            'reason' => null,
        ];

        if (! $plan['ready']) {
            $plan['reason'] = 'missing endpoint_url, shared_secret, or metadata.local_peer_uuid';

            return $plan;
        }

        if ($dryRun) {
            $plan['reason'] = 'dry_run';

            return $plan;
        }

        $headers = array_merge(
            [self::PEER_HEADER => $senderUuid],
            $this->signPayload($body, (string) $peer->shared_secret),
        );

        $response = Http::timeout(10)
            ->acceptJson()
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post(rtrim((string) $peer->endpoint_url, '/').'/api/v1/control-plane/sync/snapshot');

        $plan['status'] = $response->status();
        $plan['ok'] = $response->successful();

        return $plan;
    }

    public function latestDnsSteeringObservationSource(int $teamId): array
    {
        $snapshots = ControlPlaneSnapshot::query()
            ->whereHas('peer', fn ($query) => $query->where('team_id', $teamId))
            ->with('peer')
            ->latest('observed_at')
            ->limit(25)
            ->get();

        $health = [];
        foreach ($snapshots as $snapshot) {
            foreach (data_get($snapshot->servers_summary, 'nodes', []) as $node) {
                $key = data_get($node, 'server_uuid') ?: data_get($node, 'public_ip') ?: data_get($node, 'ip');
                if (! filled($key) || array_key_exists((string) $key, $health)) {
                    continue;
                }

                $health[(string) $key] = [
                    'healthy' => (bool) data_get($node, 'healthy', false),
                    'observed_at' => $snapshot->observed_at?->toISOString(),
                    'peer_uuid' => $snapshot->peer?->uuid,
                    'source' => 'control_plane_snapshot',
                ];
            }
        }

        return [
            'source' => 'control_plane_snapshots',
            'health' => $health,
        ];
    }

    private function validSignature(ControlPlanePeer $peer, string $body, ?string $timestamp, ?string $signature): bool
    {
        if (! filled($peer->shared_secret) || ! filled($timestamp) || ! filled($signature)) {
            return false;
        }

        try {
            $signedAt = Carbon::parse($timestamp);
        } catch (\Throwable) {
            return false;
        }

        if ($signedAt->lt(now()->subMinutes(5)) || $signedAt->gt(now()->addMinutes(5))) {
            return false;
        }

        $signature = str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature;
        $expected = hash_hmac('sha256', $timestamp.'.'.$body, (string) $peer->shared_secret);

        return hash_equals($expected, $signature);
    }

    private function snapshotPayload(array $payload): array
    {
        $snapshot = data_get($payload, 'snapshot');

        return is_array($snapshot) ? $snapshot : $payload;
    }

    private function parseObservedAt(mixed $observedAt): CarbonInterface
    {
        if (! filled($observedAt)) {
            return now();
        }

        try {
            return Carbon::parse($observedAt);
        } catch (\Throwable) {
            return now();
        }
    }

    private function serverHealthy(Server $server): bool
    {
        return (bool) data_get($server, 'settings.is_reachable')
            && (bool) data_get($server, 'settings.is_usable')
            && ! (bool) data_get($server, 'settings.force_disabled')
            && ! (bool) data_get($server, 'settings.is_build_server')
            && (string) $server->ip !== '1.2.3.4';
    }

    private function dnsSteeringReadiness(?int $teamId): array
    {
        $query = DnsSteeringPolicy::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId));

        return [
            'status' => 'observe_only',
            'policies' => (clone $query)->count(),
            'enabled_policies' => (clone $query)->where('enabled', true)->count(),
            'source' => 'dns_steering_policy',
        ];
    }

    private function edgePolicyVersions(?int $teamId): array
    {
        $policies = EdgePolicy::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->get();

        return [
            'policies' => $policies->count(),
            'versions' => $policies->map(fn (EdgePolicy $policy): array => [
                'uuid' => $policy->uuid,
                'mode' => $policy->mode,
                'scope_type' => $policy->scope_type,
                'scope_value' => $policy->scope_value,
                'updated_at' => $policy->updated_at?->toISOString(),
            ])->values()->all(),
        ];
    }
}
