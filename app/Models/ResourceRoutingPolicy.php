<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ResourceRoutingPolicy extends BaseModel
{
    use HasFactory;

    public const RESOURCE_SIMPLE_L1 = 'simple_l1';

    public const RESOURCE_MARKETPLACE = 'marketplace';

    public const RESOURCE_API = 'api';

    public const RESOURCE_CHECKOUT = 'checkout';

    public const RESOURCE_PROVIDER_GATEWAY = 'provider_gateway';

    public const RESOURCE_APPLICATION = 'application';

    public const RESOURCE_SERVICE_APPLICATION = 'service_application';

    public const LAYER_DNS = 'dns';

    public const LAYER_L7 = 'l7';

    public const STRATEGY_ACTIVE_PASSIVE = 'active_passive';

    protected $fillable = [
        'team_id',
        'resource_type',
        'resource_uuid',
        'domain',
        'routing_layer',
        'strategy',
        'enabled',
        'candidate_backends',
        'metadata',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'candidate_backends' => 'array',
        'metadata' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function observations(): HasMany
    {
        return $this->hasMany(ResourceObservation::class);
    }
}
