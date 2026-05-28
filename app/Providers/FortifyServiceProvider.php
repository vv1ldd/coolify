<?php

namespace App\Providers;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Contracts\RegisterResponse;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->instance(RegisterResponse::class, new class implements RegisterResponse
        {
            public function toResponse($request)
            {
                // First user (root) will be redirected to /settings instead of / on registration.
                if ($request->user()->currentTeam->id === 0) {
                    return redirect()->route('settings.index');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::registerView(function () {
            $isFirstUser = User::count() === 0;

            return view('auth.register', [
                'isFirstUser' => $isFirstUser,
            ]);
        });

        Fortify::loginView(function () {
            $settings = instanceSettings();

            return view('auth.login', [
                'is_registration_enabled' => $settings->is_registration_enabled,
            ]);
        });

        Fortify::authenticateUsing(function (Request $request) {
            return null;
        });
        Fortify::requestPasswordResetLinkView(function () {
            return redirect()->route('login')->withErrors([
                'sl1' => 'Password recovery is disabled. Use SL1 Identity.',
            ]);
        });
        Fortify::resetPasswordView(function ($request) {
            return redirect()->route('login')->withErrors([
                'sl1' => 'Password reset is disabled. Use SL1 Identity.',
            ]);
        });

        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);

        Fortify::confirmPasswordView(function () {
            return redirect()->route('login')->withErrors([
                'sl1' => 'Password confirmation is disabled. Use SL1 Identity.',
            ]);
        });

        Fortify::twoFactorChallengeView(function () {
            return redirect()->route('login')->withErrors([
                'sl1' => 'Two-factor challenge is disabled. Use SL1 Identity.',
            ]);
        });

        RateLimiter::for('force-password-reset', function (Request $request) {
            return Limit::perMinute(15)->by($request->user()->id);
        });

        RateLimiter::for('forgot-password', function (Request $request) {
            // Use real client IP (not spoofable forwarded headers)
            $realIp = $request->server('REMOTE_ADDR') ?? $request->ip();

            return Limit::perMinute(5)->by($realIp);
        });

        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->email;
            // Use email + real client IP (not spoofable forwarded headers)
            // server('REMOTE_ADDR') gives the actual connecting IP before proxy headers
            $realIp = $request->server('REMOTE_ADDR') ?? $request->ip();

            return Limit::perMinute(5)->by($email.'|'.$realIp);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });
    }
}
