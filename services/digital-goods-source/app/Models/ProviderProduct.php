<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderProduct extends Model
{
    protected $fillable = [
        'provider_id',
        'sku',
        'market_sku',
        'name',
        'category',
        'canonical_category',
        'reward_type',
        'purchase_price',
        'retail_price',
        'min_price',
        'max_price',
        'currency',
        'image',
        'activation_url',
        'redemption_instructions',
        'is_active',
        'data',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'min_price' => 'decimal:2',
        'max_price' => 'decimal:2',
        'is_active' => 'boolean',
        'data' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
