<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Sl1IdentityBinding extends Model
{
    protected $fillable = [
        'user_id',
        'entity_address',
        'controller_address',
        'alias',
        'display_alias',
        'proof_id',
        'last_proof',
        'last_verified_at',
    ];

    protected $casts = [
        'last_proof' => 'array',
        'last_verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
