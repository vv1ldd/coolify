<?php

namespace App\Services\Provider;

use App\Models\Provider;
use App\Models\ProviderProduct;

class ProviderCatalogAggregator
{
    public function unifiedCatalog(Provider|string $provider, bool $includeInactive = false): array
    {
        $provider = $provider instanceof Provider
            ? $provider
            : $this->resolveProvider($provider);

        if (! $provider) {
            return [
                'success' => true,
                'provider' => ['type' => is_string($provider) ? $provider : 'unknown'],
                'disabled' => true,
                'count' => 0,
                'items' => [],
            ];
        }

        $items = ProviderProduct::query()
            ->with('provider')
            ->where('provider_id', $provider->id)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('id')
            ->get()
            ->map(fn (ProviderProduct $item): array => $this->fromProviderProduct($item))
            ->values()
            ->all();

        return [
            'success' => true,
            'provider' => [
                'id' => $provider->id,
                'type' => $provider->type,
                'name' => $provider->name,
            ],
            'count' => count($items),
            'items' => $items,
        ];
    }

    public function resolveProvider(string $provider): ?Provider
    {
        $type = match ($provider) {
            'ezpin' => 'wildflow',
            'ezpin-sandbox' => 'wildflow-sandbox',
            default => $provider,
        };

        return Provider::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->first();
    }

    private function fromProviderProduct(ProviderProduct $item): array
    {
        $rawData = is_array($item->data) ? $item->data : [];
        $serviceSku = (string) $item->sku;
        $marketSku = (string) ($item->market_sku ?: $serviceSku);
        $minPrice = (float) ($item->min_price ?: $item->retail_price ?: $item->purchase_price ?: 0);
        $maxPrice = (float) ($item->max_price ?: $item->retail_price ?: $minPrice);
        $purchasePrice = (float) ($item->purchase_price ?: $minPrice ?: $maxPrice);
        $retailPrice = (float) ($item->retail_price ?: $maxPrice ?: $minPrice);

        return [
            'service_sku' => $serviceSku,
            'market_sku' => $marketSku,
            'sku' => $serviceSku,
            'name' => (string) ($item->name ?: $marketSku),
            'brand' => $item->category ?: data_get($rawData, 'brand') ?: 'Provider Catalog',
            'category' => $item->category ?: data_get($rawData, 'category') ?: 'Provider Catalog',
            'canonical_category' => $item->canonical_category,
            'region' => data_get($rawData, 'region') ?? data_get($rawData, 'regions.0.code'),
            'currency' => strtoupper((string) ($item->currency ?: data_get($rawData, 'currency') ?: 'USD')),
            'buying_price' => $purchasePrice,
            'purchase_price' => $purchasePrice,
            'retail_price' => $retailPrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice,
            'percentage_of_buying_price' => null,
            'image' => $item->image ?: data_get($rawData, 'image'),
            'reward_type' => $item->reward_type ?: data_get($rawData, 'reward_type'),
            'activation_url' => $item->activation_url ?: data_get($rawData, 'activation_url'),
            'redemption_instructions' => $item->redemption_instructions ?: data_get($rawData, 'redemption_instructions'),
            'inventory_type' => data_get($rawData, 'provider_purchase.purchase_mode') ?: 'provider_product',
            'provider_purchase' => [
                'provider_type' => $item->provider?->type,
                'provider_product_id' => $item->id,
                'service_sku' => $serviceSku,
                'market_sku' => $marketSku,
                'pre_order' => (bool) data_get($rawData, 'provider_purchase.pre_order', data_get($rawData, 'pre_order', false)),
            ],
            'status' => $item->is_active ? 'active' : 'inactive',
            'is_available' => (bool) $item->is_active,
            'raw_data' => $rawData,
        ];
    }
}
