<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgencyToolSubscription extends BaseModel
{
    use ClearsGlobalSearchCache;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_TRIAL = 'trial';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const INTERVAL_MONTHLY = 'monthly';

    public const INTERVAL_YEARLY = 'yearly';

    protected $fillable = [
        'uuid',
        'team_id',
        'agency_engagement_id',
        'vendor',
        'tool_name',
        'amount',
        'currency',
        'interval',
        'renews_at',
        'status',
        'owner_notes',
        'metadata',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'renews_at' => 'date',
        'metadata' => 'array',
    ];

    public static function ownedByCurrentTeam(): Builder
    {
        return static::query()
            ->where('team_id', currentTeam()->id)
            ->orderByRaw('LOWER(vendor)')
            ->orderByRaw('LOWER(tool_name)');
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

    public function link(): string
    {
        return route('agency.subscriptions');
    }
}
