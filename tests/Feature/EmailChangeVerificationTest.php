<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('does not create an email-controlled pending identity mutation', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);

    expect(fn () => $user->requestEmailChange('new@example.com'))
        ->toThrow(LogicException::class, 'Email is an SL1 identity projection and cannot mutate identity authority.');

    $user->refresh();

    expect($user->email)->toBe('old@example.com')
        ->and($user->pending_email)->toBeNull()
        ->and($user->email_change_code)->toBeNull()
        ->and($user->email_change_code_expires_at)->toBeNull();
});

it('does not accept email verification codes as identity authority', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $user->forceFill([
        'pending_email' => 'new@example.com',
        'email_change_code' => '123456',
        'email_change_code_expires_at' => now()->addMinutes(10),
    ])->save();

    $result = $user->confirmEmailChange('123456');
    $user->refresh();

    expect($result)->toBeFalse()
        ->and($user->email)->toBe('old@example.com');
});

it('does not expose active email change requests', function () {
    $user = User::factory()->create(['email' => 'old@example.com']);
    $user->forceFill([
        'pending_email' => 'new@example.com',
        'email_change_code' => '123456',
        'email_change_code_expires_at' => now()->addMinutes(10),
    ])->save();

    expect($user->hasEmailChangeRequest())->toBeFalse()
        ->and($user->isEmailChangeCodeValid('123456'))->toBeFalse();
});
