<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KernelPartner extends Model
{
    protected $fillable = [
        'external_id',
        'name',
        'api_token',
        'financial_secret',
        'available_balance',
        'reserved_balance',
        'currency',
        'is_active',
        'metadata',
    ];

    protected $casts = [
        'available_balance' => 'decimal:2',
        'reserved_balance' => 'decimal:2',
        'is_active' => 'boolean',
        'metadata' => 'array',
    ];

    public function orders(): HasMany
    {
        return $this->hasMany(KernelOrder::class);
    }

    public function creditReservations(): HasMany
    {
        return $this->hasMany(CreditReservation::class);
    }
}
