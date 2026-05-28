<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PolicyDecision extends Model
{
    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'intent_type',
        'target_type',
        'target_id',
        'decision',
        'capability',
        'scope',
        'risk_context',
        'reasons',
        'expires_at',
        'meta',
    ];

    protected $casts = [
        'scope' => 'array',
        'risk_context' => 'array',
        'reasons' => 'array',
        'meta' => 'array',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PolicyDecision $decision) {
            $decision->uuid ??= (string) Str::uuid();
        });

        static::updating(fn () => false);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function allows(string $capability): bool
    {
        return $this->decision === 'allow'
            && $this->capability === $capability
            && $this->expires_at->isFuture();
    }
}
