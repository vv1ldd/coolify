<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SimpleL1ControlAction extends BaseModel
{
    use HasFactory;

    public const TYPE_DNS_STEERING_APPLY = 'dns_steering_apply';

    public const ADAPTER_CLOUDFLARE_DNS = 'cloudflare_dns';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'team_id',
        'simple_l1_failover_decision_id',
        'domain',
        'action_type',
        'adapter',
        'status',
        'request',
        'outcome',
        'metadata',
        'executed_at',
    ];

    protected $casts = [
        'request' => 'array',
        'outcome' => 'array',
        'metadata' => 'array',
        'executed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Simple L1 control actions are append-only execution records.');
        });

        static::deleting(function (): void {
            throw new LogicException('Simple L1 control actions cannot be deleted.');
        });
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(SimpleL1FailoverDecision::class, 'simple_l1_failover_decision_id');
    }
}
