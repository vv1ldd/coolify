<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class InfraAuthorization extends Model
{
    protected $fillable = [
        'uuid',
        'team_id',
        'user_id',
        'policy_decision_id',
        'capability',
        'target_type',
        'target_id',
        'scope',
        'policy_decision',
        'risk_context',
        'replay_key',
        'status',
        'expires_at',
        'consumed_at',
        'consumed_by_user_id',
        'meta',
    ];

    protected $casts = [
        'scope' => 'array',
        'policy_decision' => 'array',
        'risk_context' => 'array',
        'meta' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (InfraAuthorization $authorization) {
            $authorization->uuid ??= (string) Str::uuid();
            $authorization->status ??= 'issued';
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function policyDecision(): BelongsTo
    {
        return $this->belongsTo(PolicyDecision::class);
    }
}
