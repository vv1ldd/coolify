<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResourceReconciliationAssessment extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'resource_routing_policy_id',
        'resource_type',
        'resource_uuid',
        'scope',
        'assessment_hash',
        'observation_refs',
        'conflicts',
        'candidates',
        'assessment',
        'severity',
        'assessed_at',
    ];

    protected $casts = [
        'observation_refs' => 'array',
        'conflicts' => 'array',
        'candidates' => 'array',
        'assessment' => 'array',
        'severity' => 'integer',
        'assessed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Resource reconciliation assessments are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Resource reconciliation assessments cannot be deleted.');
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ResourceRoutingPolicy::class, 'resource_routing_policy_id');
    }
}
