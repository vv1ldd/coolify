<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EdgeProjection extends BaseModel
{
    use HasFactory;

    public const TYPE_DNS_RECORD = 'dns_record_projection';

    public const TYPE_EDGE_RUNTIME = 'edge_runtime_projection';

    public const ADAPTER_CLOUDFLARE = 'cloudflare';

    public const ADAPTER_TRAEFIK = 'traefik';

    public const ADAPTER_AUTHORITATIVE_NS = 'authoritative_ns';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_NOT_SCHEDULED = 'not_scheduled';

    public const STATUS_APPLIED = 'applied';

    public const STATUS_OBSERVED = 'observed';

    public const STATUS_DRIFT = 'drift';

    public const QUORUM_UNKNOWN = 'unknown';

    public const QUORUM_PENDING = 'pending';

    public const QUORUM_CONFIRMED = 'confirmed';

    public const QUORUM_CONFLICTED = 'conflicted';

    public const INTENT_DRIFT_UNKNOWN = 'unknown';

    public const INTENT_DRIFT_NONE = 'none';

    public const INTENT_DRIFT_PENDING_APPLY = 'intent_changed_since_last_apply';

    public const CONFLICT_APPEND_ONLY_NO_ROLLBACK = 'append_only_no_rollback';

    protected $fillable = [
        'team_id',
        'domain',
        'intent_type',
        'intent_uuid',
        'intent_version',
        'intent_hash',
        'projection_type',
        'adapter',
        'projection_version',
        'projection_hash',
        'payload',
        'required_capabilities',
        'capability_snapshot_hash',
        'capability_snapshot_version',
        'status',
        'intent_drift_status',
        'conflict_policy',
        'generated_at',
        'applied_at',
        'applied_projection_hash',
        'observed_at',
        'observed_state_hash',
        'observation_confidence',
        'observation_quorum_status',
        'observation_window_started_at',
        'observation_window_ended_at',
        'metadata',
    ];

    protected $casts = [
        'intent_version' => 'integer',
        'projection_version' => 'integer',
        'payload' => 'array',
        'required_capabilities' => 'array',
        'capability_snapshot_version' => 'integer',
        'generated_at' => 'datetime',
        'applied_at' => 'datetime',
        'observed_at' => 'datetime',
        'observation_confidence' => 'integer',
        'observation_window_started_at' => 'datetime',
        'observation_window_ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EdgeProjectionVersion::class);
    }

    public function controlActions(): HasMany
    {
        return $this->hasMany(EdgeControlAction::class);
    }

    public function driftState(): string
    {
        if ($this->applied_projection_hash && $this->applied_projection_hash !== $this->projection_hash) {
            return 'intent_drift';
        }

        if ($this->observed_state_hash && $this->applied_projection_hash && $this->observed_state_hash !== $this->applied_projection_hash) {
            return 'observation_drift';
        }

        return 'in_sync';
    }

    public function observedTruthState(): string
    {
        if (! $this->observed_state_hash) {
            return 'not_observed';
        }

        if ($this->observation_quorum_status === self::QUORUM_CONFLICTED) {
            return 'observation_conflicted';
        }

        return $this->observed_state_hash === $this->applied_projection_hash
            ? 'observed_matches_applied'
            : 'observation_drift';
    }
}
