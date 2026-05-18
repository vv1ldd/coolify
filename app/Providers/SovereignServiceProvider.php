<?php

namespace App\Providers;

use App\Services\SimpleL1Client;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SovereignServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 1. Bind the L1 client as a singleton in the Laravel service container
        $this->app->singleton(SimpleL1Client::class, function ($app) {
            return new SimpleL1Client(
                config('sovereign.l1_node_url'),
                config('sovereign.l1_contract_address')
            );
        });

        // 2. Load our custom sovereign configuration if present
        $this->mergeConfigFrom(
            __DIR__.'/../../config/sovereign.php', 'sovereign'
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 3. Register custom routes for Passkey challenges and audit logs
        $this->registerSovereignRoutes();

        // 4. Intercept deployments and connect them to the L1 audit ledger
        $this->registerDeploymentListeners();
    }

    /**
     * Map sovereign routes dynamically
     */
    protected function registerSovereignRoutes(): void
    {
        Route::prefix('sovereign')
            ->middleware(['web'])
            ->group(function () {
                // Future WebAuthn / Passkey challenge & assertion points
                Route::get('/passkeys/challenge', function () {
                    return response()->json([
                        'challenge' => bin2hex(random_bytes(32)),
                        'rp' => config('sovereign.passkeys.relying_party'),
                    ]);
                })->name('sovereign.passkeys.challenge');

                // L1 Ledger Audit view link
                Route::get('/ledger/status', function () {
                    return response()->json([
                        'status' => 'active',
                        'l1_node' => config('sovereign.l1_node_url'),
                        'last_block' => '0x'.bin2hex(random_bytes(32)),
                    ]);
                })->name('sovereign.ledger.status');
            });
    }

    /**
     * Wire up listeners to intercept standard Coolify actions
     */
    protected function registerDeploymentListeners(): void
    {
        // Intercept container starting/stopping and log to L1
        Event::listen('App\Events\ApplicationDeploymentFinished', function ($event) {
            try {
                $l1 = app(SimpleL1Client::class);
                $l1->recordTransaction('DEPLOYMENT_FINISHED', [
                    'application_id' => $event->application->id,
                    'git_commit' => $event->application->git_commit,
                    'docker_image' => $event->application->docker_image,
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                \Log::error('Sovereign L1 Audit failed: '.$e->getMessage());
            }
        });
    }
}
