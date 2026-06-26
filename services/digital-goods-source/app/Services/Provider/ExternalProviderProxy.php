<?php

namespace App\Services\Provider;

use App\Models\Provider;
use Illuminate\Support\Facades\Http;

class ExternalProviderProxy
{
    public function checkAvailability(Provider $provider, string $sku, int $quantity = 1, ?float $price = null): ?array
    {
        $baseUrl = data_get($provider->credentials, 'base_url') ?: config("digital-goods-source.providers.{$provider->type}.base_url");
        if (! $baseUrl) {
            return null;
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->get(rtrim($baseUrl, '/').'/check-availability/'.rawurlencode($sku), array_filter([
                'quantity' => $quantity,
                'price' => $price,
            ], fn ($value) => $value !== null));

        if ($response->failed()) {
            return [
                'available' => false,
                'error' => $response->body(),
                'source' => 'provider-proxy',
            ];
        }

        return $response->json();
    }
}
