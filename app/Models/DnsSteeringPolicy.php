<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class DnsSteeringPolicy extends BaseModel
{
    use HasFactory;

    public const STRATEGY_STATIC = 'static';

    public const STRATEGY_ACTIVE_PASSIVE = 'active_passive';

    public const STRATEGY_WEIGHTED_ROUND_ROBIN = 'weighted_round_robin';

    public const STRATEGY_HEALTH_BASED = 'health_based';

    public const STRATEGIES = [
        self::STRATEGY_STATIC,
        self::STRATEGY_ACTIVE_PASSIVE,
        self::STRATEGY_WEIGHTED_ROUND_ROBIN,
        self::STRATEGY_HEALTH_BASED,
    ];

    protected $fillable = [
        'team_id',
        'dns_zone_id',
        'application_id',
        'domain',
        'record_name',
        'resource_type',
        'resource_uuid',
        'strategy',
        'enabled',
        'candidate_nodes',
        'desired_records',
        'last_applied_at',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'candidate_nodes' => 'array',
        'desired_records' => 'array',
        'last_applied_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'team_id',
        'dns_zone_id',
        'application_id',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function zone()
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
