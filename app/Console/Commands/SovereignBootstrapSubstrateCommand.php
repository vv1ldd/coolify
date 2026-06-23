<?php

namespace App\Console\Commands;

use App\Actions\Proxy\StartProxy;
use App\Data\ServerMetadata;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class SovereignBootstrapSubstrateCommand extends Command
{
    protected $signature = 'sovereign:bootstrap-substrate
        {--no-proxy-start : Create localhost records only; do not start Traefik}
        {--json : Emit machine-readable status}';

    protected $description = 'Ensure Team 0, localhost server, SSH key, and Traefik proxy exist for Sovereign VPS installs';

    public function handle(): int
    {
        if (isCloud()) {
            $this->emitResult('skipped', 'cloud mode does not use localhost substrate');

            return 0;
        }

        if (config('constants.coolify.is_windows_docker_desktop')) {
            $this->emitResult('skipped', 'windows docker desktop uses a dedicated testing host');

            return 0;
        }

        $this->ensureTeamZero();
        $this->ensureInstanceSettingsZero();
        $keyReady = $this->ensurePrivateKeyZero();
        $server = $this->ensureServerZero($keyReady);
        $this->ensureStandaloneDockerZero();

        if (! $server) {
            $this->emitResult('failed', 'localhost server #0 could not be created');

            return 1;
        }

        if ($this->option('no-proxy-start') || $this->shouldSkipProxyStart()) {
            $this->emitResult('ready', 'localhost substrate records are present; proxy start was skipped');

            return 0;
        }

        $server->settings->is_reachable = true;
        $server->settings->is_usable = true;
        $server->settings->save();

        try {
            $server->setupDynamicProxyConfiguration();
            StartProxy::run($server, async: false, force: true);
            $this->emitResult('ready', 'localhost substrate and Traefik proxy are ready');
        } catch (\Throwable $e) {
            $this->warn('Proxy start did not complete: '.$e->getMessage());
            $this->emitResult('ready', 'localhost substrate is ready; proxy start needs manual follow-up');
        }

        return 0;
    }

    private function ensureTeamZero(): void
    {
        if (Team::find(0) !== null) {
            return;
        }

        Team::unguarded(function () {
            Team::create([
                'id' => 0,
                'name' => 'Root Team',
                'personal_team' => true,
                'show_boarding' => false,
            ]);
        });

        $this->line('Created root team #0.');
    }

    private function ensureInstanceSettingsZero(): void
    {
        InstanceSettings::updateOrCreate(['id' => 0], []);
    }

    private function ensurePrivateKeyZero(): bool
    {
        if (PrivateKey::find(0) !== null) {
            return true;
        }

        $coolifyKeyName = '@host.docker.internal';
        $sshKeysDirectory = Storage::disk('ssh-keys')->files();
        $coolifyKeyPath = collect($sshKeysDirectory)->firstWhere(
            fn ($item) => str($item)->contains($coolifyKeyName)
        );

        if (! $coolifyKeyPath) {
            $this->warn('No SSH key found for host.docker.internal; localhost server cannot reach the host yet.');
            $this->line('Re-run install prepare_ssh_key or add the key under /data/coolify/ssh/keys/.');

            return false;
        }

        $coolifyKey = Storage::disk('ssh-keys')->get($coolifyKeyPath);
        PrivateKey::unguarded(function () use ($coolifyKey) {
            PrivateKey::create([
                'id' => 0,
                'team_id' => 0,
                'name' => 'localhost\'s key',
                'description' => 'The private key for the Coolify host machine (localhost).',
                'private_key' => $coolifyKey,
            ]);
        });

        $this->line('Registered localhost SSH key #0.');

        return true;
    }

    private function ensureServerZero(bool $keyReady): ?Server
    {
        $server = Server::find(0);
        if ($server) {
            return $server;
        }

        if (! $keyReady) {
            return null;
        }

        $serverDetails = [
            'id' => 0,
            'name' => 'localhost',
            'description' => "This is the server where Coolify is running on. Don't delete this!",
            'user' => 'root',
            'ip' => 'host.docker.internal',
            'team_id' => 0,
            'private_key_id' => 0,
            'proxy' => ServerMetadata::from([
                'type' => ProxyTypes::TRAEFIK->value,
                'status' => ProxyStatus::EXITED->value,
                'last_saved_settings' => null,
                'last_applied_settings' => null,
            ]),
        ];

        $server = Server::unguarded(fn () => Server::create($serverDetails));
        $this->line('Created localhost server #0.');

        return $server;
    }

    private function ensureStandaloneDockerZero(): void
    {
        if (StandaloneDocker::find(0) !== null) {
            return;
        }

        if (Server::find(0) === null) {
            return;
        }

        StandaloneDocker::unguarded(function () {
            StandaloneDocker::create([
                'id' => 0,
                'name' => 'localhost-coolify',
                'network' => 'coolify',
                'server_id' => 0,
            ]);
        });

        $this->line('Created localhost Docker network record #0.');
    }

    private function shouldSkipProxyStart(): bool
    {
        $profile = strtolower(trim((string) env('SOVEREIGN_HOST_PROFILE', '')));

        return $profile === 'mac-dev';
    }

    private function emitResult(string $status, string $message): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'status' => $status,
                'message' => $message,
                'team_zero' => Team::find(0) !== null,
                'server_zero' => Server::find(0) !== null,
                'private_key_zero' => PrivateKey::find(0) !== null,
            ], JSON_THROW_ON_ERROR));

            return;
        }

        if ($status === 'failed') {
            $this->error($message);
        } elseif ($status === 'skipped') {
            $this->warn($message);
        } else {
            $this->info($message);
        }
    }
}
