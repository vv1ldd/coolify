<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * PendingIntent Model
 *
 * Represents an unexecuted infrastructure intent staged in the mempool
 * waiting for cryptographic signature collection and policy clearance.
 */
class PendingIntent extends Model
{
    protected $fillable = [
        'uuid',
        'event_type',
        'target_type',
        'target_id',
        'payload',
        'signatures',
        'timeline',
        'status',
        'team_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'signatures' => 'array',
        'timeline' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
            if (empty($model->signatures)) {
                $model->signatures = [];
            }
            if (empty($model->timeline)) {
                $model->timeline = [];
            }
        });
    }

    /**
     * Morph target relation (e.g. Server, Application, etc.)
     */
    public function target()
    {
        return $this->morphTo();
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
