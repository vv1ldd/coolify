<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KernelOrder extends Model
{
    protected $fillable = [
        'kernel_partner_id',
        'provider',
        'reference',
        'service_sku',
        'quantity',
        'unit_price',
        'total_amount',
        'currency',
        'status',
        'cards',
        'payload',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'cards' => 'array',
        'payload' => 'array',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(KernelPartner::class, 'kernel_partner_id');
    }
}
