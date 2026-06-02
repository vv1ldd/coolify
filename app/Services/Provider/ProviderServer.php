<?php

namespace App\Services\Provider;

final class ProviderServer
{
    /**
     * @param  array<int, string>  $publicIps
     * @param  array<int, string>  $privateIps
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $status = null,
        public readonly ?string $primaryIp = null,
        public readonly array $publicIps = [],
        public readonly array $privateIps = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'primary_ip' => $this->primaryIp,
            'public_ips' => $this->publicIps,
            'private_ips' => $this->privateIps,
            'metadata' => $this->metadata,
        ];
    }
}
