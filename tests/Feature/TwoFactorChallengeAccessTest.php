<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
    $this->user = User::factory()->create();
    $this->team = Team::factory()->personal()->create();
    $this->team->members()->attach($this->user->id, ['role' => 'owner']);
    session(['currentTeam' => $this->team]);
});

it('does not expose two-factor-challenge as a legacy auth surface', function () {
    $this->get('/two-factor-challenge')->assertRedirect(route('login'));
});

it('does not include two-factor-challenge in allowed paths for unsubscribed accounts', function () {
    $paths = allowedPathsForUnsubscribedAccounts();

    expect($paths)->not->toContain('two-factor-challenge');
});

it('does not include two-factor-challenge in allowed paths for invalid accounts', function () {
    $paths = allowedPathsForInvalidAccounts();

    expect($paths)->not->toContain('two-factor-challenge');
});

it('does not include two-factor-challenge in allowed paths for boarding accounts', function () {
    $paths = allowedPathsForBoardingAccounts();

    expect($paths)->not->toContain('two-factor-challenge');
});

it('clears legacy force_password_reset without redirecting to a password path', function () {
    $this->user->update(['force_password_reset' => true]);

    $response = $this->actingAs($this->user)->get('/');
    $this->user->refresh();

    if ($response->isRedirect()) {
        expect($response->headers->get('Location'))->not->toContain('force-password-reset');
    }
    expect($this->user->force_password_reset)->toBeFalse();
});

it('renders 419 error page with login link instead of previous url', function () {
    $response = $this->get('/two-factor-challenge', [
        'X-CSRF-TOKEN' => 'invalid-token',
    ]);

    // The 419 page should exist and contain a link to /login
    $view = view('errors.419')->render();

    expect($view)->toContain('/login');
    expect($view)->toContain('Back to Login');
    expect($view)->toContain('This page is definitely old, not like you!');
    expect($view)->not->toContain('url()->previous()');
});
