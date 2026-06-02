<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class SimpleL1EvidencePackage extends BaseModel
{
    use HasFactory;

    public const TYPE_FAILOVER_ELECTION = 'failover_election';

    protected $fillable = [
        'team_id',
        'domain',
        'package_type',
        'evidence_hash',
        'observations',
        'metadata',
        'sealed_at',
    ];

    protected $casts = [
        'observations' => 'array',
        'metadata' => 'array',
        'sealed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Simple L1 evidence packages are immutable after sealing.');
        });

        static::deleting(function (): void {
            throw new LogicException('Simple L1 evidence packages cannot be deleted.');
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

}
