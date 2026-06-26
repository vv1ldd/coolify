<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyEngagement extends BaseModel
{
    use ClearsGlobalSearchCache;

    public const STATUS_PENDING = 'pending';

    public const STATUS_DEVELOPMENT = 'development';

    public const STATUS_TESTING = 'testing';

    public const STATUS_PRODUCTION = 'production';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ARCHIVED = 'archived';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_NORMAL = 'normal';

    public const PRIORITY_HIGH = 'high';

    protected $fillable = [
        'uuid',
        'team_id',
        'agency_client_id',
        'project_id',
        'name',
        'status',
        'priority',
        'starts_at',
        'due_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'date',
        'due_at' => 'date',
        'metadata' => 'array',
    ];

    public static function ownedByCurrentTeam(): Builder
    {
        return static::query()
            ->where('team_id', currentTeam()->id)
            ->orderByRaw('LOWER(name)');
    }

    public static function ownedByCurrentTeamCached()
    {
        return once(fn () => static::ownedByCurrentTeam()->get());
    }

    public static function statuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_DEVELOPMENT,
            self::STATUS_TESTING,
            self::STATUS_PRODUCTION,
            self::STATUS_PAUSED,
            self::STATUS_ARCHIVED,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(AgencyClient::class, 'agency_client_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(AgencyDomainAsset::class);
    }

    public function toolSubscriptions(): HasMany
    {
        return $this->hasMany(AgencyToolSubscription::class);
    }

    public function link(): string
    {
        return route('agency.engagements');
    }
}
