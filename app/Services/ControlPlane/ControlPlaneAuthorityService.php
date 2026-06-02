<?php

namespace App\Services\ControlPlane;

use App\Models\ControlPlanePeer;
use App\Models\ExecutionAuthorityLease;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ControlPlaneAuthorityService
{
    public const DEFAULT_TTL_SECONDS = 60;

    /**
     * @param  array{type?: string, key?: string, scope_type?: string, scope_key?: string}|string  $scope
     * @return array{ok: bool, status: string, lease?: ExecutionAuthorityLease, conflict?: array<string, mixed>, reason?: string}
     */
    public function acquireLease(Team|int $team, array|string $scope, ControlPlanePeer|string $peer, int $ttlSeconds = self::DEFAULT_TTL_SECONDS, ?string $reason = null, array $metadata = []): array
    {
        $teamId = $this->teamId($team);
        [$scopeType, $scopeKey] = $this->normalizeScope($scope);
        $holder = $this->resolvePeer($peer, $teamId);

        if (! $holder) {
            return [
                'ok' => false,
                'status' => 'conflict',
                'reason' => 'peer_not_found',
            ];
        }

        return DB::transaction(function () use ($teamId, $scopeType, $scopeKey, $holder, $ttlSeconds, $reason, $metadata): array {
            $this->expireStaleLeases($teamId, $scopeType, $scopeKey);

            $activeKey = $this->activeLeaseKey($teamId, $scopeType, $scopeKey);
            $existing = ExecutionAuthorityLease::query()
                ->where('active_lease_key', $activeKey)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isActive()) {
                return [
                    'ok' => false,
                    'status' => 'conflict',
                    'reason' => 'active_lease_conflict',
                    'conflict' => $this->publicLease($existing),
                ];
            }

            $now = now();
            $lease = ExecutionAuthorityLease::create([
                'team_id' => $teamId,
                'scope_type' => $scopeType,
                'scope_key' => $scopeKey,
                'active_lease_key' => $activeKey,
                'holder_peer_uuid' => $holder->uuid,
                'lease_token' => 'lease_'.Str::random(48),
                'acquired_at' => $now,
                'expires_at' => $now->copy()->addSeconds(max($ttlSeconds, 1)),
                'metadata' => array_merge($metadata, array_filter([
                    'reason' => $reason,
                    'authority_model' => 'explicit_execution_lease',
                ], fn (mixed $value): bool => filled($value))),
            ]);

            return [
                'ok' => true,
                'status' => 'acquired',
                'lease' => $lease,
            ];
        });
    }

    /**
     * @param  array{type?: string, key?: string, scope_type?: string, scope_key?: string}|string  $scope
     * @return array{ok: bool, status: string, lease?: ExecutionAuthorityLease, reason?: string}
     */
    public function validateLease(Team|int $team, array|string $scope, ?string $token): array
    {
        if (! filled($token)) {
            return [
                'ok' => false,
                'status' => 'invalid',
                'reason' => 'missing_lease_token',
            ];
        }

        $teamId = $this->teamId($team);
        [$scopeType, $scopeKey] = $this->normalizeScope($scope);
        $activeKey = $this->activeLeaseKey($teamId, $scopeType, $scopeKey);

        $lease = ExecutionAuthorityLease::query()
            ->where('active_lease_key', $activeKey)
            ->where('lease_token', $token)
            ->whereNull('released_at')
            ->first();

        if (! $lease) {
            return [
                'ok' => false,
                'status' => 'invalid',
                'reason' => 'invalid_lease_token',
            ];
        }

        if (! $lease->isActive()) {
            $this->expireStaleLeases($teamId, $scopeType, $scopeKey);

            return [
                'ok' => false,
                'status' => 'expired',
                'lease' => $lease->refresh(),
                'reason' => 'lease_expired',
            ];
        }

        return [
            'ok' => true,
            'status' => 'valid',
            'lease' => $lease,
        ];
    }

    /**
     * @param  array{type?: string, key?: string, scope_type?: string, scope_key?: string}|string  $scope
     * @return array{ok: bool, status: string, lease?: ExecutionAuthorityLease, reason?: string}
     */
    public function releaseLease(Team|int $team, array|string $scope, string $token, ?string $reason = null): array
    {
        $teamId = $this->teamId($team);
        [$scopeType, $scopeKey] = $this->normalizeScope($scope);
        $activeKey = $this->activeLeaseKey($teamId, $scopeType, $scopeKey);

        return DB::transaction(function () use ($activeKey, $token, $reason): array {
            $lease = ExecutionAuthorityLease::query()
                ->where('active_lease_key', $activeKey)
                ->where('lease_token', $token)
                ->whereNull('released_at')
                ->lockForUpdate()
                ->first();

            if (! $lease || ! $lease->isActive()) {
                return [
                    'ok' => false,
                    'status' => 'invalid',
                    'reason' => 'lease_not_active',
                ];
            }

            $lease->forceFill([
                'released_at' => now(),
                'active_lease_key' => null,
                'metadata' => array_merge($lease->metadata ?: [], array_filter([
                    'release_reason' => $reason,
                    'released_by' => 'control_plane_authority',
                ], fn (mixed $value): bool => filled($value))),
            ])->save();

            return [
                'ok' => true,
                'status' => 'released',
                'lease' => $lease->refresh(),
            ];
        });
    }

    public function expireStaleLeases(?int $teamId = null, ?string $scopeType = null, ?string $scopeKey = null): int
    {
        $query = ExecutionAuthorityLease::query()
            ->whereNotNull('active_lease_key')
            ->whereNull('released_at')
            ->where('expires_at', '<=', now())
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->when($scopeType, fn ($query) => $query->where('scope_type', $this->normalizeScopePart($scopeType)))
            ->when($scopeKey, fn ($query) => $query->where('scope_key', $this->normalizeScopePart($scopeKey)));

        $expired = 0;
        foreach ($query->get() as $lease) {
            $lease->forceFill([
                'active_lease_key' => null,
                'metadata' => array_merge($lease->metadata ?: [], [
                    'expired_at' => now()->toISOString(),
                    'expired_by' => 'control_plane_authority',
                ]),
            ])->save();
            $expired++;
        }

        return $expired;
    }

    /**
     * @return Collection<int, ExecutionAuthorityLease>
     */
    public function activeLeases(?int $teamId = null): Collection
    {
        $this->expireStaleLeases($teamId);

        return ExecutionAuthorityLease::query()
            ->whereNotNull('active_lease_key')
            ->whereNull('released_at')
            ->where('expires_at', '>', now())
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('team_id')
            ->orderBy('scope_type')
            ->orderBy('scope_key')
            ->get();
    }

    /**
     * @return array{type: string, key: string}
     */
    public function normalizeScope(array|string $scope): array
    {
        if (is_string($scope)) {
            return [
                ExecutionAuthorityLease::SCOPE_GLOBAL,
                $this->normalizeScopePart($scope),
            ];
        }

        $type = (string) (data_get($scope, 'scope_type') ?: data_get($scope, 'type') ?: ExecutionAuthorityLease::SCOPE_GLOBAL);
        $key = (string) (data_get($scope, 'scope_key') ?: data_get($scope, 'key') ?: ExecutionAuthorityLease::SCOPE_GLOBAL);

        return [
            $this->normalizeScopePart($type),
            $this->normalizeScopePart($key),
        ];
    }

    public function activeLeaseKey(int $teamId, string $scopeType, string $scopeKey): string
    {
        return hash('sha256', implode('|', [
            $teamId,
            $this->normalizeScopePart($scopeType),
            $this->normalizeScopePart($scopeKey),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function publicLease(ExecutionAuthorityLease $lease): array
    {
        return [
            'uuid' => $lease->uuid,
            'team_id' => $lease->team_id,
            'scope_type' => $lease->scope_type,
            'scope_key' => $lease->scope_key,
            'holder_peer_uuid' => $lease->holder_peer_uuid,
            'acquired_at' => $lease->acquired_at?->toISOString(),
            'expires_at' => $lease->expires_at?->toISOString(),
            'released_at' => $lease->released_at?->toISOString(),
            'metadata' => $lease->metadata ?: [],
        ];
    }

    private function teamId(Team|int $team): int
    {
        return $team instanceof Team ? $team->id : $team;
    }

    private function resolvePeer(ControlPlanePeer|string $peer, int $teamId): ?ControlPlanePeer
    {
        if ($peer instanceof ControlPlanePeer) {
            return $peer->team_id === $teamId ? $peer : null;
        }

        return ControlPlanePeer::query()
            ->where('team_id', $teamId)
            ->where('uuid', $peer)
            ->first();
    }

    private function normalizeScopePart(string $value): string
    {
        $value = trim($value);
        $value = $value === '' ? ExecutionAuthorityLease::SCOPE_GLOBAL : $value;

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
