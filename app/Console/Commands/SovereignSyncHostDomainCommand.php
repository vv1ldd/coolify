<?php

namespace App\Console\Commands;

use App\Models\InstanceSettings;
use App\Models\Server;
use Illuminate\Console\Command;
use Spatie\Url\Url;

class SovereignSyncHostDomainCommand extends Command
{
    protected $signature = 'sovereign:sync-host-domain
        {--url= : Canonical panel URL, for example https://cooly.example.com}
        {--domain= : Canonical host domain. If omitted, it is derived from --url}
        {--no-proxy-sync : Only update instance settings, do not rewrite proxy dynamic config}';

    protected $description = 'Sync Sovereign host domain, panel URL, and Coolify instance FQDN';

    public function handle(): int
    {
        $url = trim((string) ($this->option('url') ?: config('app.url')));

        if ($url === '' || in_array($url, ['http://localhost', 'https://localhost'], true)) {
            $this->warn('No canonical URL configured; skipping host domain sync.');

            return 0;
        }

        $parsed = Url::fromString($url);
        $domain = trim((string) ($this->option('domain') ?: $parsed->getHost()));
        $canonicalUrl = $parsed->getScheme().'://'.$domain;

        $settings = InstanceSettings::updateOrCreate(
            ['id' => 0],
            ['fqdn' => $canonicalUrl]
        );

        $this->info("Synced panel URL and host domain to {$settings->fqdn}.");

        if (! $this->option('no-proxy-sync')) {
            $server = Server::find(0);
            if ($server) {
                $this->syncProxyConfiguration($server);
            } else {
                $this->warn('Local server #0 was not found; proxy dynamic configuration was not updated.');
            }
        }

        return 0;
    }

    private function syncProxyConfiguration(Server $server): void
    {
        if ($this->shouldSkipLocalSshProxySync($server)) {
            $this->warn('Local server #0 is configured for localhost SSH, but SSH is not required for Sovereign host-domain sync. Panel URL was updated; proxy sync was skipped.');

            return;
        }

        try {
            $server->setupDynamicProxyConfiguration();
            $this->info('Synced proxy dynamic configuration for the local Coolify server.');
        } catch (\Throwable $e) {
            if ($this->isLocalSshRefusal($server, $e)) {
                $this->warn('Local proxy sync could not use localhost SSH. Panel URL was updated; proxy sync was skipped.');

                return;
            }

            throw $e;
        }
    }

    private function shouldSkipLocalSshProxySync(Server $server): bool
    {
        return $server->isLocalhost()
            && in_array(strtolower((string) $server->ip), ['localhost', '127.0.0.1', '::1'], true);
    }

    private function isLocalSshRefusal(Server $server, \Throwable $e): bool
    {
        return $server->isLocalhost()
            && str_contains($e->getMessage(), 'ssh: connect to host localhost port 22');
    }
}
