<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\CloudflareSetting;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\Team;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MarketplaceBootstrapCommand extends Command
{
    protected $signature = 'sovereign:marketplace-bootstrap
        {--team=0 : Team ID that owns the DNS settings and steering policies}
        {--app= : Marketplace application UUID or name}
        {--domains= : Comma-separated marketplace domains to steer}
        {--token= : Cloudflare token, defaults to MARKETPLACE_CLOUDFLARE_API_TOKEN, CLOUDFLARE_API_TOKEN, or saved Cloudflare Settings}
        {--zone-id= : Optional Cloudflare zone ID when bootstrapping one domain}
        {--ip=* : Candidate node IP. Can be repeated as name=ip or name|ip|health_url}
        {--enable : Enable DNS steering policies}
        {--json : Emit structured result as JSON}';

    protected $description = 'Bootstrap marketplace DNS steering policies for active/passive failover';

    public function handle(): int
    {
        $teamId = (int) $this->option('team');
        $team = Team::find($teamId);
        if (! $team) {
            $this->error("Team {$teamId} was not found.");

            return self::FAILURE;
        }

        $token = (string) ($this->option('token') ?: env('MARKETPLACE_CLOUDFLARE_API_TOKEN') ?: env('CLOUDFLARE_API_TOKEN') ?: '');
        $setting = CloudflareSetting::whereTeamId($teamId)->first();
        if ($token === '' && $setting) {
            $token = $setting->api_token;
        }
        if ($token === '') {
            $this->error('Cloudflare token is missing. Set MARKETPLACE_CLOUDFLARE_API_TOKEN, CLOUDFLARE_API_TOKEN, or save it in Cloudflare Settings.');

            return self::FAILURE;
        }

        $domains = $this->domains();
        if ($domains === []) {
            $this->error('No marketplace domains configured. Pass --domains or set MARKETPLACE_FAILOVER_DOMAINS.');

            return self::FAILURE;
        }

        $nodes = $this->candidateNodes();
        if ($nodes === []) {
            $this->error('No marketplace candidate node IPs configured. Set MARKETPLACE_FAILOVER_NODES, MARKETPLACE_PUBLIC_IP, SOVEREIGN_HOST_PUBLIC_IP, or pass --ip.');

            return self::FAILURE;
        }

        $zones = collect((new CloudflareDnsProvider($token))->listZones())
            ->map(fn (array $zone): array => [
                'id' => (string) data_get($zone, 'id'),
                'name' => $this->normalizeHostname((string) data_get($zone, 'name')),
            ])
            ->filter(fn (array $zone): bool => filled($zone['id']) && filled($zone['name']))
            ->values();

        CloudflareSetting::updateOrCreate(
            ['team_id' => $teamId],
            [
                'api_token' => $token,
                'last_validated_at' => now(),
                'metadata' => [
                    'source' => 'marketplace_bootstrap',
                    'validated_at' => now()->toIso8601String(),
                ],
            ],
        );

        $application = $this->application($teamId);
        $bootstrapped = [];
        foreach ($domains as $domain) {
            $zone = $this->resolveZone($zones, $domain, (string) ($this->option('zone-id') ?: ''));
            if (! $zone) {
                $bootstrapped[] = [
                    'domain' => $domain,
                    'ok' => false,
                    'reason' => 'cloudflare_zone_not_found',
                ];

                continue;
            }

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
                        'source' => 'marketplace_bootstrap',
                        'domain' => $domain,
                    ],
                ],
            );

            $policy = DnsSteeringPolicy::updateOrCreate(
                [
                    'team_id' => $teamId,
                    'domain' => $domain,
                    'resource_type' => 'marketplace',
                    'resource_uuid' => $application?->uuid ?: 'marketplace',
                ],
                [
                    'dns_zone_id' => $dnsZone->id,
                    'application_id' => $application?->id,
                    'record_name' => $domain,
                    'strategy' => DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE,
                    'enabled' => (bool) $this->option('enable') || filter_var(env('MARKETPLACE_DNS_STEERING_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
                    'candidate_nodes' => $this->nodesForDomain($nodes, $domain),
                    'metadata' => [
                        'ttl' => max((int) env('MARKETPLACE_DNS_TTL', 60), 1),
                        'proxied' => filter_var(env('MARKETPLACE_CLOUDFLARE_PROXIED', false), FILTER_VALIDATE_BOOLEAN),
                        'health_success_statuses' => [200, 204, 301, 302, 307, 308],
                        'health_user_agent' => 'Mozilla/5.0 Coolify-Marketplace-Failover',
                        'managed_by' => 'sovereign:marketplace-bootstrap',
                    ],
                ],
            );

            $bootstrapped[] = [
                'domain' => $domain,
                'ok' => true,
                'zone' => $dnsZone->name,
                'policy_uuid' => $policy->uuid,
                'policy_enabled' => (bool) $policy->enabled,
            ];
        }

        $result = [
            'ok' => collect($bootstrapped)->every(fn (array $item): bool => (bool) $item['ok']),
            'team_id' => $teamId,
            'application_uuid' => $application?->uuid,
            'candidate_nodes' => $nodes,
            'domains' => $bootstrapped,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($bootstrapped as $item) {
            $this->line(($item['ok'] ? '[ok] ' : '[skip] ').$item['domain'].' '.($item['policy_uuid'] ?? $item['reason']));
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function domains(): array
    {
        $configured = (string) ($this->option('domains') ?: env('MARKETPLACE_FAILOVER_DOMAINS', 'meanly.one,www.meanly.one,meanly.ru,www.meanly.ru,tsipruli.ge,www.tsipruli.ge,digitienda.ar,www.digitienda.ar'));

        return collect(explode(',', $configured))
            ->map(fn (string $domain): string => $this->normalizeHostname($domain))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function candidateNodes(): array
    {
        return collect($this->option('ip'))
            ->merge(explode(',', (string) env('MARKETPLACE_FAILOVER_NODES', '')))
            ->merge([
                env('MARKETPLACE_PUBLIC_IP'),
                env('SOVEREIGN_HOST_PUBLIC_IP'),
            ])
            ->map(fn (mixed $node): ?array => $this->parseCandidateNode((string) $node))
            ->filter()
            ->unique(fn (array $node): string => (string) data_get($node, 'ip'))
            ->values()
            ->map(function (array $node, int $index): array {
                return array_merge($node, [
                    'priority' => (int) data_get($node, 'priority', $index + 1),
                    'weight' => (int) data_get($node, 'weight', 1),
                ]);
            })
            ->all();
    }

    private function parseCandidateNode(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = array_map('trim', explode('|', $value));
        if (count($parts) >= 2) {
            $name = $parts[0] ?: 'marketplace-node';
            $ip = $parts[1];
        } elseif (str_contains($value, '=')) {
            [$name, $ip] = array_map('trim', explode('=', $value, 2));
        } else {
            $ip = $value;
            $name = 'marketplace-'.Str::of($ip)->replace(['.', ':'], '-')->limit(32, '');
        }

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        return array_filter([
            'name' => $name ?: 'marketplace-node',
            'ip' => $ip,
            'health_url' => $parts[2] ?? $this->healthUrlForIp($ip),
        ], fn (mixed $item): bool => filled($item));
    }

    private function nodesForDomain(array $nodes, string $domain): array
    {
        return collect($nodes)
            ->map(fn (array $node): array => array_merge($node, [
                'health_host' => $domain,
                'health_success_statuses' => [200, 204, 301, 302, 307, 308],
                'health_user_agent' => 'Mozilla/5.0 Coolify-Marketplace-Failover',
            ]))
            ->values()
            ->all();
    }

    private function healthUrlForIp(string $ip): string
    {
        $host = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? "[{$ip}]" : $ip;

        return "http://{$host}/healthcheck";
    }

    private function resolveZone(\Illuminate\Support\Collection $zones, string $domain, string $configuredZoneId = ''): ?array
    {
        if ($configuredZoneId !== '') {
            return $zones->firstWhere('id', $configuredZoneId);
        }

        return $zones
            ->sortByDesc(fn (array $zone): int => strlen($zone['name']))
            ->first(fn (array $zone): bool => $domain === $zone['name'] || str_ends_with($domain, '.'.$zone['name']));
    }

    private function application(int $teamId): ?Application
    {
        $identifier = (string) ($this->option('app') ?: env('MARKETPLACE_APPLICATION_UUID', ''));
        $query = Application::ownedByCurrentTeamAPI($teamId);
        if ($identifier !== '') {
            return $query
                ->where(fn ($builder) => $builder
                    ->where('uuid', $identifier)
                    ->orWhere('name', $identifier))
                ->first();
        }

        return $query
            ->where('git_repository', 'like', '%marketplace%')
            ->orderByDesc('updated_at')
            ->first();
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
