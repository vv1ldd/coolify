<?php

namespace App\Services\Provider;

class EzpinCatalogItemNormalizer
{
    /**
     * @return array<string, mixed>|null
     */
    public function fromCatalogItem(array $item): ?array
    {
        $serviceSku = trim((string) ($item['sku'] ?? ''));
        if ($serviceSku === '') {
            return null;
        }

        $price = $item['price'] ?? null;
        $minPrice = is_array($price)
            ? $this->number($price['min'] ?? $price['selling_price'] ?? $item['min_price'] ?? 0)
            : $this->number($price ?? $item['min_price'] ?? 0);
        $maxPrice = is_array($price)
            ? $this->number($price['max'] ?? $price['selling_price'] ?? $item['max_price'] ?? $minPrice)
            : $this->number($price ?? $item['max_price'] ?? $minPrice);

        $currency = $this->currency($item['currency'] ?? 'USD');
        $purchasePrice = abs($minPrice - $maxPrice) < 0.0001 ? $minPrice : $minPrice;

        return [
            'service_sku' => $serviceSku,
            'sku' => $serviceSku,
            'market_sku' => 'WFC-'.substr(hash('sha256', $serviceSku), 0, 16),
            'name' => trim((string) ($item['name'] ?? $item['title'] ?? $serviceSku)),
            'category' => (string) ($item['category'] ?? data_get($item, 'categories.0.name', 'Gift Cards')),
            'canonical_category' => 'gift_cards',
            'reward_type' => $item['reward_type_text'] ?? $item['reward_type'] ?? 'Gift-Card',
            'purchase_price' => $purchasePrice,
            'retail_price' => $maxPrice > 0 ? $maxPrice : $purchasePrice,
            'min_price' => $minPrice,
            'max_price' => $maxPrice > 0 ? $maxPrice : $minPrice,
            'currency' => $currency,
            'image' => $item['image'] ?? null,
            'activation_url' => $item['activation_url'] ?? $item['redemption_url'] ?? null,
            'redemption_instructions' => $item['description'] ?? null,
            'is_active' => true,
            'is_available' => true,
            'data' => $item + [
                'provider_purchase' => [
                    'provider_type' => 'ezpin',
                    'purchase_mode' => 'catalog',
                    'provider_identifier_field' => 'sku',
                    'provider_identifier' => $serviceSku,
                    'sku' => $serviceSku,
                    'source' => 'dgs_ezpin_upstream_pull',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fromRetailerItem(array $item): ?array
    {
        $serviceSku = trim((string) ($item['product_code'] ?? ''));
        if ($serviceSku === '') {
            return null;
        }

        $product = is_array($item['product'] ?? null) ? $item['product'] : [];
        $faceValue = $this->number($item['price'] ?? 0);
        $buyingPrice = $this->number($item['buying_price'] ?? $item['cost'] ?? $faceValue);
        $currency = $this->currency($item['currency'] ?? $product['currency'] ?? 'USD');

        return [
            'service_sku' => $serviceSku,
            'sku' => $serviceSku,
            'market_sku' => 'WFR-'.substr(hash('sha256', $serviceSku), 0, 16),
            'name' => trim((string) ($product['title'] ?? $product['name'] ?? $item['product_name'] ?? $serviceSku)),
            'category' => (string) ($product['category'] ?? 'Retailer Catalog'),
            'canonical_category' => 'gift_cards',
            'reward_type' => $product['reward_type_text'] ?? $product['reward_type'] ?? 'Gift-Card',
            'purchase_price' => $buyingPrice,
            'retail_price' => $faceValue > 0 ? $faceValue : $buyingPrice,
            'min_price' => $faceValue > 0 ? $faceValue : $buyingPrice,
            'max_price' => $faceValue > 0 ? $faceValue : $buyingPrice,
            'currency' => $currency,
            'image' => $product['image'] ?? $item['image'] ?? null,
            'activation_url' => $product['activation_url'] ?? null,
            'redemption_instructions' => $product['description'] ?? null,
            'is_active' => true,
            'is_available' => true,
            'data' => $item + [
                'provider_purchase' => [
                    'provider_type' => 'ezpin',
                    'purchase_mode' => 'retailer',
                    'provider_identifier_field' => 'product_code',
                    'provider_identifier' => $serviceSku,
                    'source' => 'dgs_ezpin_upstream_pull',
                ],
            ],
        ];
    }

    private function number(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function currency(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value['code'] ?? $value['currency'] ?? reset($value);
        }

        $code = strtoupper(trim((string) $value));

        return $code !== '' ? $code : 'USD';
    }
}
