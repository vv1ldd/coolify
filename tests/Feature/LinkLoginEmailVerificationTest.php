<?php

use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Once;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([DecideWhatToDoWithUser::class, CheckForcePasswordReset::class]);
    Once::flush();
    if (! InstanceSettings::find(0)) {
        $settings = new InstanceSettings;
        $settings->id = 0;
        $settings->saveQuietly();
    }
});

describe('invitation link login', function () {
    test('magic link delivery does not auto-verify the email address', function () {
        $team = Team::factory()->create();
        $password = 'test-password-123';
        $user = User::factory()->create([
            'email' => 'invitee@example.com',
            'password' => Hash::make($password),
            'email_verified_at' => null,
        ]);
        $user->teams()->attach($team->id, ['role' => 'member']);

        $this->get(route('auth.link', ['token' => 'legacy-token']))->assertRedirect(route('login'));

        $user->refresh();
        expect($user->email_verified_at)->toBeNull();
    });

    test('magic link delivery cannot authenticate a user', function () {
        $team = Team::factory()->create();
        $password = 'test-password-123';
        $user = User::factory()->create([
            'email' => 'invitee2@example.com',
            'password' => Hash::make($password),
            'email_verified_at' => null,
        ]);
        $user->teams()->attach($team->id, ['role' => 'member']);

        $this->get(route('auth.link', ['token' => 'legacy-token']))->assertRedirect(route('login'));

        expect(auth()->id())->toBeNull();
    });
});
