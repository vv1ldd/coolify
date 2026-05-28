<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityObservation extends BaseModel
{
    protected $fillable = [
        'uuid',
        'team_id',
        'trace_id',
        'session_id',
        'source_ip',
        'source_hash',
        'layer',
        'signal',
        'score',
        'action',
        'method',
        'path',
        'user_agent',
        'metadata',
        'observed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'observed_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeOwnedByTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }
}
