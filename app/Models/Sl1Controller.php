<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sl1Controller extends Model
{
    protected $fillable = [
        'entity_address',
        'controller_address',
        'credential_hash',
        'credential_id',
        'credential_public_key',
        'transports',
        'rp_id',
        'sign_count',
        'status',
        'added_at',
        'last_used_at',
        'revoked_at',
        'meta',
    ];

    protected $casts = [
        'transports' => 'array',
        'sign_count' => 'integer',
        'added_at' => 'datetime',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
        'meta' => 'array',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(Sl1Entity::class, 'entity_address', 'entity_address');
    }
}
