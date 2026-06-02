<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceObservation extends BaseModel
{
    use HasFactory;

    public const STATUS_HEALTHY = 'healthy';

    public const STATUS_UNHEALTHY = 'unhealthy';

    public const STATUS_UNKNOWN = 'unknown';

    protected $fillable = [
        'team_id',
        'resource_routing_policy_id',
        'resource_type',
        'resource_uuid',
        'backend',
        'status',
        'latency_ms',
        'confidence',
        'evidence',
        'observed_at',
    ];

    protected $casts = [
        'latency_ms' => 'integer',
        'confidence' => 'integer',
        'evidence' => 'array',
        'observed_at' => 'datetime',
    ];

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ResourceRoutingPolicy::class, 'resource_routing_policy_id');
    }
}
