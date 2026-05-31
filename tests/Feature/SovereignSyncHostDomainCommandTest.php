<?php

use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
});

test('host domain sync skips localhost ssh proxy sync for local server', function () {
    $team = Team::factory()->create();

    Server::unguarded(fn () => Server::create([
        'id' => 0,
        'team_id' => $team->id,
        'name' => 'localhost',
        'ip' => 'localhost',
        'user' => 'root',
        'port' => 22,
        'private_key_id' => 1,
    ]));

    $this->artisan('sovereign:sync-host-domain', [
        '--url' => 'https://host.example.test',
        '--domain' => 'host.example.test',
    ])
        ->expectsOutput('Synced panel URL and host domain to https://host.example.test.')
        ->expectsOutput('Local server #0 uses local substrate routing; SSH proxy sync is not required for Sovereign host-domain sync. Panel URL was updated; proxy sync was skipped.')
        ->assertExitCode(0);

    expect(InstanceSettings::first()->fqdn)->toBe('https://host.example.test');
});

test('host domain sync treats host docker internal as local substrate', function () {
    $team = Team::factory()->create();

    Server::unguarded(fn () => Server::create([
        'id' => 0,
        'team_id' => $team->id,
        'name' => 'localhost',
        'ip' => 'host.docker.internal',
        'user' => 'root',
        'port' => 22,
        'private_key_id' => 1,
    ]));

    $this->artisan('sovereign:sync-host-domain', [
        '--url' => 'https://host.example.test',
        '--domain' => 'host.example.test',
    ])
        ->expectsOutput('Synced panel URL and host domain to https://host.example.test.')
        ->expectsOutput('Local server #0 uses local substrate routing; SSH proxy sync is not required for Sovereign host-domain sync. Panel URL was updated; proxy sync was skipped.')
        ->assertExitCode(0);

    expect(InstanceSettings::first()->fqdn)->toBe('https://host.example.test');
});
