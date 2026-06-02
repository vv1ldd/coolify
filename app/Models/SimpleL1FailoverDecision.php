<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SimpleL1FailoverDecision extends BaseModel
{
    use HasFactory;

    public const RECOMMENDATION_NO_CHANGE = 'no_change';

    public const RECOMMENDATION_PROMOTE = 'promote';

    public const RECOMMENDATION_NO_TARGET = 'no_healthy_target';

    protected $fillable = [
        'team_id',
        'dns_steering_policy_id',
        'simple_l1_evidence_package_id',
        'domain',
        'decided_at',
        'previous_target',
        'new_target',
        'recommendation',
        'reason',
        'evidence_hash',
        'evidence',
        'applied_result',
        'applied_by',
        'applied_at',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
        'evidence' => 'array',
        'applied_result' => 'array',
        'applied_at' => 'datetime',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(DnsSteeringPolicy::class, 'dns_steering_policy_id');
    }

    public function evidencePackage(): BelongsTo
    {
        return $this->belongsTo(SimpleL1EvidencePackage::class, 'simple_l1_evidence_package_id');
    }

    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(SimpleL1DecisionEvidenceLink::class, 'simple_l1_failover_decision_id');
    }

    public function controlActions(): HasMany
    {
        return $this->hasMany(SimpleL1ControlAction::class, 'simple_l1_failover_decision_id');
    }
}
