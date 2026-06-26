<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SourceLedgerEntry extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'scope',
        'event_type',
        'entity_type',
        'entity_id',
        'provider',
        'partner_external_id',
        'reference',
        'payload',
        'input_state',
        'output_state',
        'fingerprint',
        'previous_fingerprint',
        'meta',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'input_state' => 'array',
        'output_state' => 'array',
        'meta' => 'array',
        'occurred_at' => 'datetime',
    ];

    public static function boot(): void
    {
        parent::boot();

        static::updating(function (): never {
            throw new \LogicException('Source ledger entries are immutable.');
        });

        static::deleting(function (): never {
            throw new \LogicException('Source ledger entries cannot be deleted.');
        });
    }
}
