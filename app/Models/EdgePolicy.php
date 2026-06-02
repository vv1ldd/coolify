<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use LogicException;

class EdgePolicy extends BaseModel
{
    use HasFactory;

    public const MODE_OFF = 'off';

    public const MODE_NORMAL = 'normal';

    public const MODE_STRICT = 'strict';

    public const MODE_UNDER_ATTACK = 'under_attack';

    public const MODES = [
        self::MODE_OFF,
        self::MODE_NORMAL,
        self::MODE_STRICT,
        self::MODE_UNDER_ATTACK,
    ];

    public const SCOPE_DOMAIN = 'domain';

    public const SCOPE_APPLICATION = 'application';

    public const SCOPE_SERVICE = 'service';

    public const SCOPE_SERVER = 'server';

    public const SCOPE_REGION = 'region';

    public const SCOPE_TEAM = 'team';

    public const SCOPE_DEFAULT = 'default';

    public const SCOPE_TYPES = [
        self::SCOPE_DOMAIN,
        self::SCOPE_APPLICATION,
        self::SCOPE_SERVICE,
        self::SCOPE_SERVER,
        self::SCOPE_REGION,
        self::SCOPE_TEAM,
        self::SCOPE_DEFAULT,
    ];

    protected static bool $edgePolicyServiceMutation = false;

    protected $fillable = [
        'team_id',
        'name',
        'mode',
        'scope_type',
        'scope_value',
        'ruleset',
        'challenge_enabled',
        'silent_drop_enabled',
        'rate_limit_average',
        'rate_limit_burst',
        'in_flight_limit',
        'metadata',
    ];

    protected $casts = [
        'challenge_enabled' => 'boolean',
        'silent_drop_enabled' => 'boolean',
        'rate_limit_average' => 'integer',
        'rate_limit_burst' => 'integer',
        'in_flight_limit' => 'integer',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (): void {
            if (! static::$edgePolicyServiceMutation) {
                throw new LogicException('EdgePolicy can only be mutated through EdgePolicyService.');
            }
        });

        static::deleting(function (): void {
            if (! static::$edgePolicyServiceMutation) {
                throw new LogicException('EdgePolicy can only be mutated through EdgePolicyService.');
            }
        });
    }

    public static function mutateThroughService(callable $callback): mixed
    {
        $previous = static::$edgePolicyServiceMutation;
        static::$edgePolicyServiceMutation = true;

        try {
            return $callback();
        } finally {
            static::$edgePolicyServiceMutation = $previous;
        }
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}
