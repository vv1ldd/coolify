<?php

use App\Models\InstanceSettings;
use App\Models\User;
use App\Notifications\TransactionalEmails\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::forget('instance_settings_fqdn_host');
    Once::flush();
    InstanceSettings::unguarded(fn () => InstanceSettings::updateOrCreate(['id' => 0], []));
});

function callResetUrl(ResetPassword $notification, $notifiable): string
{
    $method = new ReflectionMethod($notification, 'resetUrl');

    return $method->invoke($notification, $notifiable);
}

it('routes reset-password notifications back to login using configured FQDN', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com', 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('test-token-abc', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('https://coolify.example.com/')
        ->toContain('/login')
        ->not->toContain('test-token-abc')
        ->not->toContain(urlencode($user->email))
        ->not->toContain('localhost');
});

it('routes reset-password notifications back to login using public IP when no FQDN is configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('test-token-abc', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('65.21.3.91')
        ->toContain('/login')
        ->not->toContain('test-token-abc')
        ->not->toContain('evil.com');
});

it('is immune to X-Forwarded-Host header poisoning when FQDN is set', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com', 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    // Simulate a request with a spoofed X-Forwarded-Host header
    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('https://coolify.example.com/')
        ->toContain('/login')
        ->not->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('is immune to X-Forwarded-Host header poisoning when using IP only', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => '65.21.3.91']
    );
    Once::flush();

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('65.21.3.91')
        ->toContain('/login')
        ->not->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('routes reset-password notifications back to login with bracketed IPv6 when no FQDN is configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => '2001:db8::1']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('ipv6-token', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('[2001:db8::1]')
        ->toContain('/login')
        ->not->toContain('ipv6-token')
        ->not->toContain(urlencode($user->email));
});

it('is immune to X-Forwarded-Host header poisoning when using IPv6 only', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => '2001:db8::1']
    );
    Once::flush();

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('poisoned-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('[2001:db8::1]')
        ->toContain('/login')
        ->not->toContain('poisoned-token')
        ->not->toContain('evil.com');
});

it('uses APP_URL fallback when no FQDN or public IPs are configured', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => null, 'public_ipv4' => null, 'public_ipv6' => null]
    );
    Once::flush();

    config(['app.url' => 'http://my-coolify.local']);

    $user = User::factory()->create();

    $this->withHeaders([
        'X-Forwarded-Host' => 'evil.com',
    ])->get('/');

    $notification = new ResetPassword('fallback-token', isTransactionalEmail: false);
    $url = callResetUrl($notification, $user);

    expect($url)
        ->toStartWith('http://my-coolify.local/')
        ->toContain('/login')
        ->not->toContain('fallback-token')
        ->not->toContain('evil.com');
});

it('does not generate password reset token links', function () {
    InstanceSettings::updateOrCreate(
        ['id' => 0],
        ['fqdn' => 'https://coolify.example.com']
    );
    Once::flush();

    $user = User::factory()->create();
    $notification = new ResetPassword('my-token', isTransactionalEmail: false);

    $url = callResetUrl($notification, $user);

    expect($url)
        ->toContain('/login')
        ->not->toContain('/reset-password/')
        ->not->toContain('my-token')
        ->not->toContain(urlencode($user->email));
});
