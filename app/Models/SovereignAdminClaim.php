<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SovereignAdminClaim extends Model
{
    protected $fillable = [
        'user_id',
        'claim_token_hash',
        'expires_at',
        'claimed_at',
        'claimed_entity_address',
        'last_proof',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'last_proof' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return is_null($this->claimed_at) && $this->expires_at->isFuture();
    }
}
