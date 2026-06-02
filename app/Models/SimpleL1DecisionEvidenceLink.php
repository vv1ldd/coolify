<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SimpleL1DecisionEvidenceLink extends BaseModel
{
    use HasFactory;

    public const TYPE_PRIMARY = 'primary';

    public const TYPE_SUPPORTING = 'supporting';

    public const TYPE_OVERRIDE = 'override';

    public const TYPE_OPERATOR = 'operator';

    public const TYPE_POLICY = 'policy';

    public const TYPE_HEALTH = 'health';

    public const TYPE_LATENCY = 'latency';

    public const TYPE_CONTINUITY = 'continuity';

    protected $fillable = [
        'team_id',
        'simple_l1_failover_decision_id',
        'simple_l1_evidence_package_id',
        'link_type',
        'metadata',
        'linked_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'linked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Simple L1 decision evidence links are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Simple L1 decision evidence links cannot be deleted.');
        });
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(SimpleL1FailoverDecision::class, 'simple_l1_failover_decision_id');
    }

    public function evidencePackage(): BelongsTo
    {
        return $this->belongsTo(SimpleL1EvidencePackage::class, 'simple_l1_evidence_package_id');
    }

    public function causalityStatement(): string
    {
        return "decision:{$this->simple_l1_failover_decision_id} depends_on evidence:{$this->simple_l1_evidence_package_id} as {$this->link_type}";
    }
}
