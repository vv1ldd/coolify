<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Sl1IdentityEvent extends Model
{
    protected $fillable = [
        'uuid',
        'event_type',
        'entity_address',
        'controller_address',
        'proof_id',
        'source',
        'event_hash',
        'previous_event_hash',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Sl1IdentityEvent $event) {
            $event->uuid ??= (string) Str::uuid();
            $event->occurred_at ??= now();
        });
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Sl1Entity::class, 'entity_address', 'entity_address');
    }
}
