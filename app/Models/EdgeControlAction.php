<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EdgeControlAction extends BaseModel
{
    use HasFactory;

    public const TYPE_DNS_RECORD_UPSERT = 'dns_record_upsert';

    public const TYPE_DNS_RECORD_DELETE = 'dns_record_delete';

    public const TYPE_EDGE_RUNTIME_APPLY = 'edge_runtime_apply';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'team_id',
        'edge_projection_id',
        'domain',
        'action_type',
        'adapter',
        'status',
        'request',
        'outcome',
        'projection_hash',
        'applied_projection_hash',
        'observed_state_hash',
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
            throw new LogicException('Edge control actions are append-only execution records.');
        });

        static::deleting(function (): void {
            throw new LogicException('Edge control actions cannot be deleted.');
        });
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(EdgeProjection::class, 'edge_projection_id');
    }
}
