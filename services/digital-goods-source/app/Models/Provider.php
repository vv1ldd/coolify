<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    protected $fillable = [
        'name',
        'type',
        'is_active',
        'sync_status',
        'credentials',
        'settings',
        'last_sync_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'credentials' => 'array',
        'settings' => 'array',
        'last_sync_at' => 'datetime',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(ProviderProduct::class);
    }
}
