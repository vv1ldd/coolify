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
                $server->setupDynamicProxyConfiguration();
                $this->info('Synced proxy dynamic configuration for the local Coolify server.');
            } else {
                $this->warn('Local server #0 was not found; proxy dynamic configuration was not updated.');
            }
        }

        return 0;
    }
}
