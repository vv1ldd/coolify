<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sl1Entity extends Model
{
    protected $fillable = [
        'entity_address',
        'alias',
        'display_alias',
        'status',
        'current_event_hash',
        'metadata',
        'last_verified_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_verified_at' => 'datetime',
    ];

    public function controllers(): HasMany
    {
        return $this->hasMany(Sl1Controller::class, 'entity_address', 'entity_address');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Sl1IdentityEvent::class, 'entity_address', 'entity_address');
    }
}
