<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class CloudflareSetting extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'team_id',
        'api_token',
        'last_validated_at',
        'metadata',
    ];

    protected $casts = [
        'api_token' => 'encrypted',
        'last_validated_at' => 'datetime',
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
}
