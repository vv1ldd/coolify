<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExecutionAuthorityLease extends BaseModel
{
    use HasFactory;

    public const SCOPE_DNS_ZONE = 'dns_zone';

    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_SERVER = 'server';

    public const SCOPE_PROVIDER_ACTION = 'provider_action';

    public const SCOPE_EDGE_POLICY = 'edge_policy';

    public const SCOPE_GLOBAL = 'global';

    protected $fillable = [
        'uuid',
        'team_id',
        'scope_type',
        'scope_key',
        'active_lease_key',
        'holder_peer_uuid',
        'lease_token',
        'acquired_at',
        'expires_at',
        'released_at',
        'metadata',
    ];

    protected $casts = [
        'acquired_at' => 'datetime',
        'expires_at' => 'datetime',
        'released_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'team_id',
        'lease_token',
        'active_lease_key',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function holderPeer(): BelongsTo
    {
        return $this->belongsTo(ControlPlanePeer::class, 'holder_peer_uuid', 'uuid');
    }

    public function isActive(): bool
    {
        return filled($this->active_lease_key)
            && ! $this->released_at
            && $this->expires_at?->isFuture();
    }
}
