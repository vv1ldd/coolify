<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SimpleL1NodeObservation extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'domain',
        'observed_at',
        'observer_node',
        'target_node',
        'target_ip',
        'status',
        'latency_ms',
        'http_code',
        'health_source',
        'health_url',
        'evidence',
    ];

    protected $casts = [
        'observed_at' => 'datetime',
        'latency_ms' => 'integer',
        'http_code' => 'integer',
        'evidence' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
