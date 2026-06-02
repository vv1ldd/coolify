<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class DnsZone extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'provider',
        'name',
        'provider_zone_id',
        'api_token',
        'metadata',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'api_token',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function records()
    {
        return $this->hasMany(DnsRecord::class);
    }
}
