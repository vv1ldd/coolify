<?php

namespace App\Providers;

use App\Services\SimpleL1Client;
use Illuminate\Support\Facades\Event;
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
        // 4. Intercept system actions and connect them to the L1 audit ledger
        $this->registerSovereignListeners();
    }

    /**
     * Wire up listeners to intercept standard Coolify actions and sign them to Simple L1
     */
    protected function registerSovereignListeners(): void
    {
        // 1. Audit Server onboarding and validations
        Event::listen('App\Events\ServerValidated', function ($event) {
            try {
                $l1 = app(SimpleL1Client::class);
                $l1->recordTransaction('SERVER_VALIDATION_INTENT', [
                    'server_uuid' => $event->serverUuid,
                    'team_id' => $event->teamId,
                    'sovereign_mandate_id' => session('sovereign_mandate_id', 'legacy_session'),
                    'sovereign_mandate_hash' => session('sovereign_mandate_hash', 'legacy_session'),
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                \Log::error('Sovereign L1 Audit Server validation failed: '.$e->getMessage());
            }
        });

        // 2. Audit Configuration updates and secret mutations
        Event::listen('App\Events\ApplicationConfigurationChanged', function ($event) {
            try {
                $l1 = app(SimpleL1Client::class);
                $l1->recordTransaction('CONFIG_MUTATION_INTENT', [
                    'team_id' => $event->teamId,
                    'sovereign_mandate_id' => session('sovereign_mandate_id', 'legacy_session'),
                    'sovereign_mandate_hash' => session('sovereign_mandate_hash', 'legacy_session'),
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                \Log::error('Sovereign L1 Audit Config change failed: '.$e->getMessage());
            }
        });

        // 3. Audit Deployments
        Event::listen('App\Events\ApplicationDeploymentFinished', function ($event) {
            try {
                $l1 = app(SimpleL1Client::class);
                $l1->recordTransaction('DEPLOYMENT_FINISHED', [
                    'application_id' => $event->application->id,
                    'git_commit' => $event->application->git_commit,
                    'docker_image' => $event->application->docker_image,
                    'sovereign_mandate_id' => session('sovereign_mandate_id', 'legacy_session'),
                    'sovereign_mandate_hash' => session('sovereign_mandate_hash', 'legacy_session'),
                    'timestamp' => now()->toIso8601String(),
                ]);
            } catch (\Throwable $e) {
                \Log::error('Sovereign L1 Audit Deployment failed: '.$e->getMessage());
            }
        });
    }
}
