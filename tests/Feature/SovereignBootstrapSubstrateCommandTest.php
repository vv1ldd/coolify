<?php

use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

const TEST_SSH_PRIVATE_KEY = <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create(['id' => 0]));
});

test('bootstrap substrate creates team server and docker records', function () {
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put('id.root@host.docker.internal', TEST_SSH_PRIVATE_KEY);

    $this->artisan('sovereign:bootstrap-substrate', ['--no-proxy-start' => true])
        ->assertExitCode(0);

    expect(Team::find(0))->not->toBeNull()
        ->and(Server::find(0))->not->toBeNull()
        ->and(PrivateKey::find(0))->not->toBeNull()
        ->and(StandaloneDocker::find(0))->not->toBeNull();

    expect(Server::find(0)->ip)->toBe('host.docker.internal');
});

test('bootstrap substrate is idempotent', function () {
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put('id.root@host.docker.internal', TEST_SSH_PRIVATE_KEY);

    $this->artisan('sovereign:bootstrap-substrate', ['--no-proxy-start' => true])
        ->assertExitCode(0);
    $this->artisan('sovereign:bootstrap-substrate', ['--no-proxy-start' => true])
        ->assertExitCode(0);

    expect(Team::count())->toBe(1)
        ->and(Server::count())->toBe(1)
        ->and(PrivateKey::count())->toBe(1);
});

test('bootstrap substrate skips proxy start on mac-dev profile', function () {
    putenv('SOVEREIGN_HOST_PROFILE=mac-dev');
    Storage::fake('ssh-keys');
    Storage::disk('ssh-keys')->put('id.root@host.docker.internal', TEST_SSH_PRIVATE_KEY);

    $this->artisan('sovereign:bootstrap-substrate')
        ->expectsOutputToContain('proxy start was skipped')
        ->assertExitCode(0);
});
