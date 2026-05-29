<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Sl1PeerNode extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_UNREACHABLE = 'unreachable';

    public const STATUS_INVALID = 'invalid';

    protected $fillable = [
        'uuid',
        'name',
        'issuer',
        'status',
        'runtime',
        'storage',
        'capabilities',
        'last_status',
        'last_issuer_document',
        'last_error',
        'last_verified_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_status' => 'array',
        'last_issuer_document' => 'array',
        'last_verified_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Sl1PeerNode $peer) {
            $peer->uuid ??= (string) Str::uuid();
            $peer->status ??= self::STATUS_PENDING;
        });
    }
}
