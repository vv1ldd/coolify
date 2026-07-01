<?php

namespace App\Console\Commands;

use App\Models\Provider;
use App\Services\Provider\EzpinCatalogItemNormalizer;
use App\Services\Provider\EzpinUpstreamCatalogClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncCatalogsCommand extends Command
{
    protected $signature = 'wildflow:sync-catalogs
        {provider? : Provider type to sync}
        {--source= : Optional JSON endpoint returning unified catalog items}
        {--pull-upstream : Pull catalog directly from EzPin using authority EZPIN_* credentials}';

    protected $description = 'Sync provider catalog projections into Digital Goods Source.';

    public function handle(EzpinUpstreamCatalogClient $upstreamClient, EzpinCatalogItemNormalizer $normalizer): int
    {
        if ((bool) config('digital-goods-source.edge_mode')) {
            $this->error('Direct upstream pull is disabled on edge nodes; use CATALOG_SOURCE_URL mirror.');

            return self::FAILURE;
        }

        $providers = Provider::query()
            ->where('is_active', true)
            ->when($this->argument('provider'), fn ($query, $provider) => $query->where('type', $provider))
            ->get();

        if ($providers->isEmpty()) {
            $this->warn('No active providers found.');

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            if ($this->option('pull-upstream')) {
                if ($this->pullUpstream($provider, $upstreamClient, $normalizer) === self::FAILURE) {
                    return self::FAILURE;
                }

                continue;
            }

            if ($this->pullHttpSource($provider) === self::FAILURE) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function pullUpstream(
        Provider $provider,
        EzpinUpstreamCatalogClient $upstreamClient,
        EzpinCatalogItemNormalizer $normalizer,
    ): int {
        $this->info("Provider {$provider->type}: pulling EzPin upstream catalog...");

        try {
            $payload = $upstreamClient->pullCatalogs();
        } catch (\Throwable $e) {
            $this->error("Provider {$provider->type}: upstream pull failed — {$e->getMessage()}");

            return self::FAILURE;
        }

        $items = [];
        foreach ($payload['catalog'] as $raw) {
            $normalized = $normalizer->fromCatalogItem($raw);
            if ($normalized !== null) {
                $items[$normalized['sku']] = $normalized;
            }
        }
        foreach ($payload['retailer'] as $raw) {
            $normalized = $normalizer->fromRetailerItem($raw);
            if ($normalized !== null) {
                $items[$normalized['sku']] = $normalized;
            }
        }

        $seenSkus = array_keys($items);
        $deactivated = $provider->products()->whereNotIn('sku', $seenSkus)->update(['is_active' => false]);

        $count = 0;
        foreach ($items as $item) {
            $this->upsertProduct($provider, $item);
            $count++;
        }

        $provider->forceFill([
            'last_sync_at' => now(),
            'sync_status' => "upstream synced {$count} item(s)",
        ])->save();

        $this->info("Provider {$provider->type}: upstream synced {$count} item(s); deactivated {$deactivated} stale row(s).");

        return self::SUCCESS;
    }

    private function pullHttpSource(Provider $provider): int
    {
        $source = $this->option('source')
            ?: data_get($provider->settings, 'catalog_source_url')
            ?: config('digital-goods-source.catalog_source_url');
        if (! $source) {
            $this->line("Provider {$provider->type}: no catalog source configured; skipping.");

            return self::SUCCESS;
        }

        $request = Http::timeout(120)->acceptJson();
        $authToken = data_get($provider->settings, 'catalog_source_auth_token')
            ?: config('digital-goods-source.catalog_source_auth_token');
        if (filled($authToken)) {
            $request = $request->withHeaders(['X-Auth-Token' => (string) $authToken]);
        }

        $response = $request->get($source);
        if ($response->failed()) {
            $this->error("Provider {$provider->type}: failed to fetch catalog source.");

            return self::FAILURE;
        }

        $items = $response->json('items') ?? $response->json('catalog.results') ?? $response->json('data') ?? [];
        $count = 0;
        foreach ($items as $item) {
            $sku = (string) data_get($item, 'service_sku', data_get($item, 'sku', ''));
            if ($sku === '') {
                continue;
            }

            $this->upsertProduct($provider, $item + ['sku' => $sku]);
            $count++;
        }

        $provider->forceFill([
            'last_sync_at' => now(),
            'sync_status' => "synced {$count} item(s)",
        ])->save();

        $this->info("Provider {$provider->type}: synced {$count} item(s).");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function upsertProduct(Provider $provider, array $item): void
    {
        $sku = (string) data_get($item, 'service_sku', data_get($item, 'sku', ''));
        $provider->products()->updateOrCreate(
            ['sku' => $sku],
            [
                'market_sku' => data_get($item, 'market_sku', $sku),
                'name' => data_get($item, 'name', $sku),
                'category' => data_get($item, 'category'),
                'canonical_category' => data_get($item, 'canonical_category'),
                'reward_type' => data_get($item, 'reward_type'),
                'purchase_price' => (float) data_get($item, 'purchase_price', data_get($item, 'buying_price', 0)),
                'retail_price' => (float) data_get($item, 'retail_price', 0),
                'min_price' => (float) data_get($item, 'min_price', 0),
                'max_price' => (float) data_get($item, 'max_price', 0),
                'currency' => strtoupper((string) data_get($item, 'currency', 'USD')),
                'image' => data_get($item, 'image'),
                'activation_url' => data_get($item, 'activation_url'),
                'redemption_instructions' => data_get($item, 'redemption_instructions'),
                'is_active' => (bool) data_get($item, 'is_available', data_get($item, 'is_active', true)),
                'data' => $item,
            ],
        );
    }
}
