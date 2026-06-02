<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResourceArbitrationDecision extends BaseModel
{
    use HasFactory;

    public const DECISION_NO_CHANGE = 'no_change';

    public const DECISION_SWITCH_BACKEND = 'switch_backend';

    public const DECISION_UNRESOLVED = 'unresolved_conflict';

    protected $fillable = [
        'team_id',
        'resource_routing_policy_id',
        'resource_reconciliation_assessment_id',
        'edge_projection_id',
        'scope',
        'authority_scope',
        'authority_actor',
        'authority_basis',
        'supersedes_decision_id',
        'decision',
        'decision_hash',
        'reason',
        'assessment',
        'rationale',
        'metadata',
        'decided_at',
    ];

    protected $casts = [
        'assessment' => 'array',
        'rationale' => 'array',
        'metadata' => 'array',
        'decided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Resource arbitration decisions are append-only authority records.');
        });

        static::deleting(function (): void {
            throw new LogicException('Resource arbitration decisions cannot be deleted.');
        });
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(ResourceRoutingPolicy::class, 'resource_routing_policy_id');
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(EdgeProjection::class, 'edge_projection_id');
    }

    public function reconciliationAssessment(): BelongsTo
    {
        return $this->belongsTo(ResourceReconciliationAssessment::class, 'resource_reconciliation_assessment_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_decision_id');
    }
}
