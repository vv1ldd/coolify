<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Sl1NodeIdentity extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const ALGORITHM_ED25519 = 'ed25519';

    protected $fillable = [
        'node_id',
        'issuer',
        'signature_algorithm',
        'public_key',
        'private_key_ciphertext',
        'status',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];
}
