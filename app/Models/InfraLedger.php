<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Sovereign Infrastructure Intent Ledger Entry
 *
 * This model is APPEND-ONLY by design.
 * Never update or delete records — each entry is a sealed state transition.
 */
class InfraLedger extends Model
{
    /**
     * Stage 1: 'infra_ledger' falls back to the same operational DB.
     * Stage 2: Set LEDGER_DB_* env vars to point to a dedicated append-only PostgreSQL.
     */
    protected $connection = 'infra_ledger';

    public $timestamps = false; // We manage created_at manually for determinism

    protected $table = 'infra_ledger';

    protected $fillable = [
        'team_id',
        'trigger_source',
        'event_type',
        'entity_type',
        'entity_id',
        'payload',
        'input_state',
        'output_state',
        'fingerprint',
        'previous_fingerprint',
        'meta',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'input_state' => 'array',
        'output_state' => 'array',
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function getConnectionName()
    {
        return app()->environment('testing') ? config('database.default') : parent::getConnectionName();
    }

    /**
     * Prevent any update — the ledger is immutable.
     */
    public static function boot(): void
    {
        parent::boot();

        static::updating(function () {
            throw new \LogicException('InfraLedger entries are immutable. State transitions are append-only.');
        });

        static::deleting(function () {
            throw new \LogicException('InfraLedger entries cannot be deleted. The chain is permanent.');
        });
    }

    /**
     * Resolve the human-readable display name of the entity.
     */
    public function getEntityNameAttribute(): string
    {
        if (! $this->entity_type || ! $this->entity_id) {
            return 'System';
        }
        try {
            $model = app($this->entity_type)->find($this->entity_id);

            return $model?->name ?? "{$this->entity_type}#{$this->entity_id}";
        } catch (\Throwable) {
            return "{$this->entity_type}#{$this->entity_id}";
        }
    }
}
