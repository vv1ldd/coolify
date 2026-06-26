<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;

class RunCatalogSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public ?string $provider = null)
    {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('digital-goods-source-catalog-sync'))->releaseAfter(600)];
    }

    public function handle(): void
    {
        $parameters = [];
        if ($this->provider) {
            $parameters['provider'] = $this->provider;
        }

        $exit = Artisan::call('wildflow:sync-catalogs', $parameters);
        if ($exit !== 0) {
            throw new \RuntimeException('wildflow:sync-catalogs exited with code '.$exit);
        }
    }
}
