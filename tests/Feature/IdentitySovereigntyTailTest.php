<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Models\Sl1IdentityBinding;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('fortify password registration recovery and otp features are disabled', function () {
    $features = config('fortify.features');

    expect($features)
        ->not->toContain(Features::registration())
        ->not->toContain(Features::resetPasswords())
        ->not->toContain(Features::updatePasswords())
        ->not->toContain(Features::twoFactorAuthentication());
});

test('password registration action is a constitutional dead path', function () {
    expect(fn () => app(CreateNewUser::class)->create([
        'name' => 'Legacy User',
        'email' => 'legacy@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]))->toThrow(LogicException::class, 'Password registration is constitutionally disabled. Use SL1 Identity.');

    expect(User::whereEmail('legacy@example.com')->exists())->toBeFalse();
});

test('fortify profile update cannot use email as identity mutation authority', function () {
    $user = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    expect(fn () => app(UpdateUserProfileInformation::class)->update($user, [
        'name' => 'New Name',
        'email' => 'new@example.com',
    ]))->toThrow(LogicException::class, 'Email is an SL1 identity projection and cannot mutate identity authority.');

    $user->refresh();

    expect($user->name)->toBe('Old Name')
        ->and($user->email)->toBe('old@example.com');
});

test('team member list displays sl1 identity instead of synthetic projection email', function () {
    $user = User::factory()->create([
        'name' => '@operator',
        'email' => 'sl1-2439bdd3e2a8386badc0@identity.sl1.local',
    ]);
    $team = Team::factory()->create();
    $team->members()->attach($user->id, ['role' => 'owner']);
    Sl1IdentityBinding::create([
        'user_id' => $user->id,
        'entity_address' => 'sl1e_operator',
        'alias' => '@operator',
        'display_alias' => '@operator',
        'last_verified_at' => now(),
    ]);

    $this->actingAs($user);
    session(['currentTeam' => $team]);

    Livewire::test(\App\Livewire\Team\Member::class, ['member' => $user])
        ->assertSee('@operator')
        ->assertDontSee('@identity.sl1.local');
});
