<?php

namespace App\Console\Commands;

use App\Models\Provider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncCatalogsCommand extends Command
{
    protected $signature = 'wildflow:sync-catalogs
        {provider? : Provider type to sync}
        {--source= : Optional JSON endpoint returning unified catalog items}';

    protected $description = 'Sync provider catalog projections into Digital Goods Source.';

    public function handle(): int
    {
        $providers = Provider::query()
            ->where('is_active', true)
            ->when($this->argument('provider'), fn ($query, $provider) => $query->where('type', $provider))
            ->get();

        if ($providers->isEmpty()) {
            $this->warn('No active providers found.');

            return self::SUCCESS;
        }

        foreach ($providers as $provider) {
            $source = $this->option('source') ?: data_get($provider->settings, 'catalog_source_url');
            if (! $source) {
                $this->line("Provider {$provider->type}: no catalog source configured; skipping.");
                continue;
            }

            $response = Http::timeout(120)->acceptJson()->get($source);
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
                $count++;
            }

            $provider->forceFill([
                'last_sync_at' => now(),
                'sync_status' => "synced {$count} item(s)",
            ])->save();

            $this->info("Provider {$provider->type}: synced {$count} item(s).");
        }

        return self::SUCCESS;
    }
}
