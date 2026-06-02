<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ResourceControlAction extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'resource_arbitration_decision_id',
        'edge_control_action_id',
        'action_type',
        'adapter',
        'status',
        'request',
        'outcome',
        'executed_at',
    ];

    protected $casts = [
        'request' => 'array',
        'outcome' => 'array',
        'executed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Resource control actions are append-only execution records.');
        });
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(ResourceArbitrationDecision::class, 'resource_arbitration_decision_id');
    }

    public function edgeControlAction(): BelongsTo
    {
        return $this->belongsTo(EdgeControlAction::class, 'edge_control_action_id');
    }
}
