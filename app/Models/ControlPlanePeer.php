<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ControlPlanePeer extends BaseModel
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_ONLINE = 'online';

    public const STATUS_STALE = 'stale';

    public const STATUS_INVALID = 'invalid';

    public const ROLE_OBSERVER = 'observer';

    public const ROLE_CONTROL_PLANE = 'control_plane';

    public const ROLE_EDGE_AGENT = 'edge_agent';

    public const CAPABILITY_EDGE_RUNTIME = 'edge_runtime';

    public const CAPABILITY_AUTHORITATIVE_DNS = 'authoritative_dns';

    public const CAPABILITY_HEALTH_OBSERVER = 'health_observer';

    public const CAPABILITY_CONTROL_PLANE = 'control_plane';

    protected $fillable = [
        'uuid',
        'team_id',
        'name',
        'endpoint_url',
        'public_ip',
        'region',
        'role',
        'shared_secret',
        'shared_secret_hash',
        'last_seen_at',
        'status',
        'capabilities',
        'metadata',
    ];

    protected $casts = [
        'shared_secret' => 'encrypted',
        'last_seen_at' => 'datetime',
        'capabilities' => 'array',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'team_id',
        'shared_secret',
        'shared_secret_hash',
    ];

    protected static function booted(): void
    {
        static::saving(function (ControlPlanePeer $peer): void {
            if (filled($peer->shared_secret)) {
                $peer->shared_secret_hash = hash('sha256', (string) $peer->shared_secret);
            }
        });
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ControlPlaneSnapshot::class);
    }

    public function executionAuthorityLeases(): HasMany
    {
        return $this->hasMany(ExecutionAuthorityLease::class, 'holder_peer_uuid', 'uuid');
    }

    public function freshnessStatus(int $staleAfterMinutes = 5): string
    {
        if (! $this->last_seen_at) {
            return $this->status ?: self::STATUS_PENDING;
        }

        if ($this->last_seen_at->lt(now()->subMinutes($staleAfterMinutes))) {
            return self::STATUS_STALE;
        }

        return $this->status === self::STATUS_INVALID ? self::STATUS_INVALID : self::STATUS_ONLINE;
    }

    public function providesCapability(string $capability): bool
    {
        return collect($this->capabilities ?: [])->contains($capability);
    }

    public function providesAllCapabilities(array $capabilities): bool
    {
        return collect($capabilities)
            ->filter()
            ->every(fn (string $capability): bool => $this->providesCapability($capability));
    }

    public function capabilitySnapshotHash(): string
    {
        $payload = [
            'uuid' => $this->uuid,
            'status' => $this->freshnessStatus(),
            'capabilities' => collect($this->capabilities ?: [])->sort()->values()->all(),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function capabilitySnapshotVersion(): int
    {
        return (int) data_get($this->metadata, 'capability_version', 1);
    }
}
