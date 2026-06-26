<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyDomainAsset extends BaseModel
{
    use ClearsGlobalSearchCache;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRING = 'expiring';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_RELEASED = 'released';

    protected $fillable = [
        'uuid',
        'team_id',
        'agency_engagement_id',
        'dns_zone_id',
        'domain',
        'registrar',
        'expires_at',
        'status',
        'ownership_notes',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'date',
        'metadata' => 'array',
    ];

    public static function ownedByCurrentTeam(): Builder
    {
        return static::query()
            ->where('team_id', currentTeam()->id)
            ->orderByRaw('LOWER(domain)');
    }

    public static function ownedByCurrentTeamCached()
    {
        return once(fn () => static::ownedByCurrentTeam()->get());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function engagement(): BelongsTo
    {
        return $this->belongsTo(AgencyEngagement::class, 'agency_engagement_id');
    }

    public function dnsZone(): BelongsTo
    {
        return $this->belongsTo(DnsZone::class);
    }

    public function link(): string
    {
        return route('agency.domains');
    }
}
