<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class DnsRecord extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'dns_zone_id',
        'application_id',
        'provider_record_id',
        'type',
        'name',
        'content',
        'ttl',
        'proxied',
        'comment',
        'metadata',
    ];

    protected $casts = [
        'ttl' => 'integer',
        'proxied' => 'boolean',
        'metadata' => 'array',
    ];

    protected $hidden = [
        'id',
        'dns_zone_id',
        'application_id',
    ];

    public function zone()
    {
        return $this->belongsTo(DnsZone::class, 'dns_zone_id');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
