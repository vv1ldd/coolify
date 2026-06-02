<?php

namespace App\Console\Commands;

use App\Models\CloudflareSetting;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\Team;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class SimpleL1BootstrapCommand extends Command
{
    protected $signature = 'sovereign:simple-l1-bootstrap
        {--team=0 : Team ID that owns the DNS settings and steering policy}
        {--domain= : Public Simple L1 domain, defaults to SIMPLE_L1_DOMAIN or SL1_CONNECT_ISSUER host}
        {--record= : DNS record name, defaults to the domain}
        {--token= : Cloudflare token, defaults to SIMPLE_L1_CLOUDFLARE_API_TOKEN or CLOUDFLARE_API_TOKEN}
        {--zone-id= : Cloudflare zone ID, defaults to SIMPLE_L1_CLOUDFLARE_ZONE_ID or autodiscovery}
        {--ip=* : Candidate node IP. Can be repeated}
        {--enable : Enable the DNS steering policy}
        {--json : Emit structured result as JSON}';

    protected $description = 'Bootstrap embedded Simple L1 DNS failover policy for Sovereign Coolify nodes';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $team = Team::find($teamId);
        if (! $team) {
            $this->error("Team {$teamId} was not found.");

            return self::FAILURE;
        }

        $domain = $this->normalizeHostname((string) ($this->option('domain') ?: env('SIMPLE_L1_DOMAIN') ?: parse_url((string) env('SL1_CONNECT_ISSUER', 'https://simplel1.online'), PHP_URL_HOST)));
        if ($domain === '') {
            $this->error('Simple L1 domain is missing. Set SIMPLE_L1_DOMAIN or SL1_CONNECT_ISSUER.');

            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: env('SIMPLE_L1_CLOUDFLARE_API_TOKEN') ?: env('CLOUDFLARE_API_TOKEN') ?: '');
        $setting = CloudflareSetting::whereTeamId($teamId)->first();
        if ($token === '' && $setting) {
            $token = $setting->api_token;
        }
        if ($token === '') {
            $this->error('Cloudflare token is missing. Set SIMPLE_L1_CLOUDFLARE_API_TOKEN, CLOUDFLARE_API_TOKEN, or save it in Cloudflare Settings.');

            return self::FAILURE;
        }

        $zone = $this->resolveCloudflareZone($token, $domain, (string) ($this->option('zone-id') ?: env('SIMPLE_L1_CLOUDFLARE_ZONE_ID') ?: ''));
        if (! $zone) {
            $this->error("Cloudflare zone for {$domain} was not found.");

            return self::FAILURE;
        }

        $nodes = $this->candidateNodes($domain);
        if ($nodes === []) {
            $this->error('No Simple L1 candidate node IPs configured. Set SIMPLE_L1_PUBLIC_IP, SOVEREIGN_HOST_PUBLIC_IP, SIMPLE_L1_FAILOVER_NODES, or pass --ip.');

            return self::FAILURE;
        }

        $cloudflareSetting = CloudflareSetting::updateOrCreate(
            ['team_id' => $teamId],
            [
                'api_token' => $token,
                'last_validated_at' => now(),
                'metadata' => [
                    'source' => 'simple_l1_bootstrap',
                    'zone_count' => null,
                    'validated_at' => now()->toIso8601String(),
                ],
            ],
        );

        $dnsZone = DnsZone::updateOrCreate(
            [
                'team_id' => $teamId,
                'provider' => 'cloudflare',
                'name' => $zone['name'],
            ],
            [
                'provider_zone_id' => $zone['id'],
                'api_token' => $token,
                'metadata' => [
                    'source' => 'simple_l1_bootstrap',
                    'domain' => $domain,
                ],
            ],
        );

        $recordName = $this->normalizeHostname((string) ($this->option('record') ?: env('SIMPLE_L1_RECORD_NAME') ?: $domain));
        $policy = DnsSteeringPolicy::updateOrCreate(
            [
                'team_id' => $teamId,
                'domain' => $domain,
                'resource_type' => 'simple_l1',
                'resource_uuid' => 'embedded-runtime',
            ],
            [
                'dns_zone_id' => $dnsZone->id,
                'record_name' => $recordName,
                'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
                'enabled' => (bool) $this->option('enable') || filter_var(env('SIMPLE_L1_DNS_STEERING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                'candidate_nodes' => $nodes,
                'metadata' => [
                    'ttl' => max((int) env('SIMPLE_L1_DNS_TTL', 60), 1),
                    'proxied' => filter_var(env('SIMPLE_L1_CLOUDFLARE_PROXIED', false), FILTER_VALIDATE_BOOLEAN),
                    'health_endpoint' => rtrim((string) env('SIMPLE_L1_ISSUER_URL', "https://{$domain}/sl1"), '/').'/status',
                    'managed_by' => 'sovereign:simple-l1-bootstrap',
                ],
            ],
        );

        $result = [
            'ok' => true,
            'team_id' => $teamId,
            'domain' => $domain,
            'zone' => [
                'uuid' => $dnsZone->uuid,
                'name' => $dnsZone->name,
                'provider_zone_id' => $dnsZone->provider_zone_id,
            ],
            'cloudflare_setting_uuid' => $cloudflareSetting->uuid,
            'policy_uuid' => $policy->uuid,
            'policy_enabled' => (bool) $policy->enabled,
            'candidate_nodes' => $nodes,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Simple L1 failover policy bootstrapped for {$domain}.");
        $this->line("Zone: {$dnsZone->name} ({$dnsZone->provider_zone_id})");
        $this->line("Policy: {$policy->uuid} enabled=".($policy->enabled ? 'yes' : 'no'));
        $this->line('Candidates: '.collect($nodes)->map(fn (array $node): string => $node['name'].'='.(data_get($node, 'ip') ?: data_get($node, 'ipv6')))->join(', '));

        return self::SUCCESS;
    }

    private function resolveCloudflareZone(string $token, string $domain, string $configuredZoneId = ''): ?array
    {
        $zones = collect((new CloudflareDnsProvider($token))->listZones())
            ->map(fn (array $zone): array => [
                'id' => (string) data_get($zone, 'id'),
                'name' => $this->normalizeHostname((string) data_get($zone, 'name')),
            ])
            ->filter(fn (array $zone): bool => filled($zone['id']) && filled($zone['name']));

        if ($configuredZoneId !== '') {
            return $zones->firstWhere('id', $configuredZoneId);
        }

        return $zones
            ->sortByDesc(fn (array $zone): int => strlen($zone['name']))
            ->first(fn (array $zone): bool => $domain === $zone['name'] || str_ends_with($domain, '.'.$zone['name']));
    }

    private function candidateNodes(string $domain): array
    {
        $nodes = collect($this->option('ip'))
            ->merge(explode(',', (string) env('SIMPLE_L1_FAILOVER_NODES', '')))
            ->merge([
                env('SIMPLE_L1_PUBLIC_IP'),
                env('SOVEREIGN_HOST_PUBLIC_IP'),
            ])
            ->map(fn (mixed $node): ?array => $this->parseCandidateNode((string) $node, $domain))
            ->filter()
            ->unique(fn (array $node): string => (string) (data_get($node, 'ip') ?: data_get($node, 'ipv6')))
            ->values();

        return $nodes
            ->map(function (array $node, int $index): array {
                return array_merge($node, [
                    'priority' => (int) data_get($node, 'priority', $index + 1),
                    'weight' => (int) data_get($node, 'weight', 1),
                ]);
            })
            ->all();
    }

    private function parseCandidateNode(string $value, string $domain): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = array_map('trim', explode('|', $value));
        if (count($parts) >= 2) {
            $name = $parts[0] ?: 'simple-l1-node';
            $ip = $parts[1];
        } elseif (str_contains($value, '=')) {
            [$name, $ip] = array_map('trim', explode('=', $value, 2));
        } else {
            $ip = $value;
            $name = 'simple-l1-'.Str::of($ip)->replace(['.', ':'], '-')->limit(32, '');
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        $healthUrl = $parts[2] ?? $this->healthUrlForIp($ip);

        return array_filter([
            'name' => $name ?: 'simple-l1-node',
            filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'ipv6' : 'ip' => $ip,
            'health_url' => $healthUrl,
            'health_host' => $domain,
        ], fn (mixed $item): bool => filled($item));
    }

    private function healthUrlForIp(string $ip): string
    {
        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;

        return "http://{$host}/healthcheck";
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = trim($hostname);
        if (str_contains($hostname, '://')) {
            $hostname = (string) parse_url($hostname, PHP_URL_HOST);
        }

        return rtrim(strtolower($hostname), '.');
    }
}
