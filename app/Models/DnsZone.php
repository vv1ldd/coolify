<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class DnsZone extends BaseModel
{
    use HasFactory;

    public const PROVIDER_CLOUDFLARE = 'cloudflare';

    public const PROVIDER_AUTHORITATIVE = 'authoritative';

    public const PROVIDER_HYBRID = 'hybrid';

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

    public function adapterMode(): string
    {
        return (string) data_get($this->metadata, 'adapter_mode', $this->provider ?: self::PROVIDER_CLOUDFLARE);
    }

    public function cloudflareSyncEnabled(): bool
    {
        return (bool) data_get($this->metadata, 'cloudflare_sync', in_array($this->adapterMode(), [self::PROVIDER_CLOUDFLARE, self::PROVIDER_HYBRID], true));
    }

    public function authoritativeEnabled(): bool
    {
        return (bool) data_get($this->metadata, 'authoritative', in_array($this->adapterMode(), [self::PROVIDER_AUTHORITATIVE, self::PROVIDER_HYBRID], true));
    }
}
