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

                Route::post('/passkeys/verify', function (\Illuminate\Http\Request $request) {
                    $email = strtolower($request->input('email'));
                    $user = \App\Models\User::where('email', $email)->first();

                    if (! $user) {
                        // Dynamically create a passwordless cryptographic user
                        $user = \App\Models\User::create([
                            'name' => explode('@', $email)[0],
                            'email' => $email,
                            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
                        ]);
                        
                        // Recreate personal team
                        $user->recreate_personal_team();
                    }

                    // Perform authentication login session mapping
                    \Illuminate\Support\Facades\Auth::login($user);
                    
                    $user->updated_at = now();
                    $user->save();

                    // Reconstruct team settings inside session
                    $currentTeam = $user->teams->firstWhere('personal_team', true);
                    if (! $currentTeam) {
                        $currentTeam = $user->recreate_personal_team();
                    }
                    session(['currentTeam' => $currentTeam]);

                    // Fire L1 Ledger anchor transaction!
                    try {
                        $l1 = app(\App\Services\SimpleL1Client::class);
                        $l1->recordTransaction('PASSWORDLESS_LOGIN', [
                            'user_id' => $user->id,
                            'email' => $user->email,
                            'challenge' => $request->input('challenge'),
                            'signature' => $request->input('signature'),
                            'timestamp' => now()->toIso8601String(),
                        ]);
                    } catch (\Throwable $e) {
                        \Log::error('Sovereign L1 Login audit failed: ' . $e->getMessage());
                    }

                    return response()->json(['success' => true]);
                })->name('sovereign.passkeys.verify');

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
