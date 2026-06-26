<?php

namespace App\Models;

use App\Traits\ClearsGlobalSearchCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgencyClient extends BaseModel
{
    use ClearsGlobalSearchCache;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'uuid',
        'team_id',
        'name',
        'contact_name',
        'contact_email',
        'country',
        'timezone',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public static function ownedByCurrentTeam(): Builder
    {
        return static::query()->where('team_id', currentTeam()->id)->orderByRaw('LOWER(name)');
    }

    public static function ownedByCurrentTeamCached()
    {
        return once(fn () => static::ownedByCurrentTeam()->get());
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function engagements(): HasMany
    {
        return $this->hasMany(AgencyEngagement::class);
    }

    public function link(): string
    {
        return route('agency.clients');
    }
}
