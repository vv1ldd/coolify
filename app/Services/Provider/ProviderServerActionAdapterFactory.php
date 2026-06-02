<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;

final class ProviderServerActionAdapterFactory
{
    /**
     * @return array<int, string>
     */
    public function supportedProviderKeys(): array
    {
        return [
            'selectel_vds',
            'hostinger_vps',
        ];
    }

    public function make(string $providerKey, ?CloudProviderToken $token = null): ProviderServerActionAdapter
    {
        return match ($providerKey) {
            'selectel_vds' => new SelectelVdsProviderAdapter($token),
            'hostinger_vps' => new HostingerVpsProviderAdapter($token),
            default => new NoopProviderServerActionAdapter,
        };
    }

    public function supports(string $providerKey): bool
    {
        return in_array($providerKey, $this->supportedProviderKeys(), true);
    }
}
