<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class EdgeProjectionVersion extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'edge_projection_id',
        'projection_version',
        'intent_hash',
        'projection_hash',
        'payload',
        'metadata',
        'generated_at',
    ];

    protected $casts = [
        'projection_version' => 'integer',
        'payload' => 'array',
        'metadata' => 'array',
        'generated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Edge projection versions are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Edge projection versions cannot be deleted.');
        });
    }

    public function projection(): BelongsTo
    {
        return $this->belongsTo(EdgeProjection::class, 'edge_projection_id');
    }
}
