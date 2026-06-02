<?php

namespace App\Services\Dns;

use App\Models\Application;
use App\Models\CloudflareSetting;
use App\Models\DnsZone;
use App\Models\ServiceApplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DomainDnsProvisioningService
{
    public function __construct(
        private readonly DnsZoneService $dnsZones,
    ) {}

    public function provisionApplication(Application $application, int $teamId, ?string $domains = null, bool $proxied = false): array
    {
        $application->loadMissing(['destination.server']);
        $target = $this->targetForApplication($application);

        return $this->provisionDomains(
            teamId: $teamId,
            domains: $domains ?? $application->fqdn,
            target: $target,
            recordMetadata: [
                'resource_type' => 'application',
                'resource_uuid' => $application->uuid,
                'managed_from' => 'resource_domain_settings',
            ],
            applicationUuid: $application->uuid,
            proxied: $proxied,
        );
    }

    public function provisionServiceApplication(ServiceApplication $serviceApplication, int $teamId, ?string $domains = null, bool $proxied = false): array
    {
        $serviceApplication->loadMissing(['service.destination.server', 'service.server']);
        $target = $this->targetForServiceApplication($serviceApplication);

        return $this->provisionDomains(
            teamId: $teamId,
            domains: $domains ?? $serviceApplication->fqdn,
            target: $target,
            recordMetadata: [
                'resource_type' => 'service_application',
                'resource_uuid' => $serviceApplication->uuid,
                'service_uuid' => data_get($serviceApplication, 'service.uuid'),
                'service_name' => data_get($serviceApplication, 'service.name'),
                'container_name' => $serviceApplication->name,
                'managed_from' => 'resource_domain_settings',
            ],
            proxied: $proxied,
        );
    }

    public function availabilityForDomains(int $teamId, ?string $domains): array
    {
        $hosts = $this->hostsFromDomainList($domains);
        $zones = $this->zonesForTeam($teamId);
        $matches = $hosts
            ->map(function (string $host) use ($teamId, $zones): array {
                $zone = $this->matchingZone($zones, $host) ?? $this->discoverMatchingZone($teamId, $host);

                return [
                    'host' => $host,
                    'zone' => $zone?->name,
                    'zone_uuid' => $zone?->uuid,
                    'proxy_forced_dns_only' => $zone ? $this->shouldForceDnsOnly($zone, $host) : false,
                ];
            });

        return [
            'available' => $matches->contains(fn (array $match): bool => filled($match['zone'])),
            'matches' => $matches->filter(fn (array $match): bool => filled($match['zone']))->values()->all(),
            'unmatched' => $matches->filter(fn (array $match): bool => blank($match['zone']))->pluck('host')->values()->all(),
        ];
    }

    private function provisionDomains(int $teamId, ?string $domains, ?string $target, array $recordMetadata, ?string $applicationUuid = null, bool $proxied = false): array
    {
        $hosts = $this->hostsFromDomainList($domains);
        if ($hosts->isEmpty()) {
            return $this->result(message: 'No valid domains were found to configure in Cloudflare.');
        }

        if (blank($target)) {
            return $this->result(
                skipped: $hosts->all(),
                message: 'DNS was not configured because the resource server does not have a target IP or hostname.'
            );
        }

        $zones = $this->zonesForTeam($teamId);
        $records = collect();
        $skipped = collect();

        foreach ($hosts as $host) {
            $zone = $this->matchingZone($zones, $host) ?? $this->discoverMatchingZone($teamId, $host);
            if (! $zone) {
                $skipped->push($host);

                continue;
            }

            $records->push($this->dnsZones->upsertManagedRecord($zone, [
                'type' => $this->recordTypeForTarget($target),
                'name' => $host,
                'content' => $target,
                'ttl' => 1,
                'proxied' => $proxied,
                'application_uuid' => $applicationUuid,
                'comment' => 'Managed from resource domain settings.',
                'metadata' => $recordMetadata,
            ], $teamId));
        }

        return $this->result(
            records: $records->map(fn ($record): array => [
                'uuid' => $record->uuid,
                'type' => $record->type,
                'name' => $record->name,
                'content' => $record->content,
                'zone' => $record->zone?->name,
            ])->all(),
            skipped: $skipped->all(),
            message: $this->message($records->count(), $skipped->count()),
        );
    }

    private function hostsFromDomainList(?string $domains): Collection
    {
        return collect(explode(',', (string) $domains))
            ->map(fn (string $domain): string => trim($domain))
            ->filter()
            ->map(fn (string $domain): ?string => $this->hostFromDomain($domain))
            ->filter()
            ->unique()
            ->values();
    }

    private function hostFromDomain(string $domain): ?string
    {
        $candidate = trim($domain);
        if ($candidate === '') {
            return null;
        }

        if (! str_contains($candidate, '://')) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->normalizeHost($host);
    }

    private function zonesForTeam(int $teamId): Collection
    {
        return DnsZone::whereTeamId($teamId)
            ->get()
            ->sortByDesc(fn (DnsZone $zone): int => strlen($zone->name))
            ->values();
    }

    private function matchingZone(Collection $zones, string $host): ?DnsZone
    {
        return $zones->first(function (DnsZone $zone) use ($host): bool {
            $zoneName = $this->normalizeHost($zone->name);

            return $host === $zoneName || str_ends_with($host, '.'.$zoneName);
        });
    }

    private function discoverMatchingZone(int $teamId, string $host): ?DnsZone
    {
        if (! Schema::hasTable('cloudflare_settings')) {
            return null;
        }

        $setting = CloudflareSetting::whereTeamId($teamId)->first();
        if (! $setting) {
            return null;
        }

        $zone = collect((new CloudflareDnsProvider($setting->api_token))->listZones())
            ->map(fn (array $zone): array => [
                'id' => (string) data_get($zone, 'id'),
                'name' => $this->normalizeHost((string) data_get($zone, 'name')),
            ])
            ->filter(fn (array $zone): bool => filled($zone['id']) && filled($zone['name']))
            ->sortByDesc(fn (array $zone): int => strlen($zone['name']))
            ->first(fn (array $zone): bool => $host === $zone['name'] || str_ends_with($host, '.'.$zone['name']));

        if (! $zone) {
            return null;
        }

        return DnsZone::updateOrCreate(
            [
                'team_id' => $teamId,
                'provider' => 'cloudflare',
                'name' => $zone['name'],
            ],
            [
                'provider_zone_id' => $zone['id'],
                'api_token' => $setting->api_token,
                'metadata' => [
                    'discovered_from_cloudflare_settings' => true,
                    'discovered_at' => now()->toIso8601String(),
                ],
            ],
        );
    }

    private function targetForApplication(Application $application): ?string
    {
        return $this->normalizeTarget(data_get($application, 'destination.server.ip'));
    }

    private function targetForServiceApplication(ServiceApplication $serviceApplication): ?string
    {
        return $this->normalizeTarget(
            data_get($serviceApplication, 'service.destination.server.ip')
                ?: data_get($serviceApplication, 'service.server.ip')
        );
    }

    private function normalizeTarget(?string $target): ?string
    {
        $target = trim((string) $target);

        return $target === '' ? null : $target;
    }

    private function recordTypeForTarget(string $target): string
    {
        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'AAAA';
        }

        if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'A';
        }

        return 'CNAME';
    }

    private function shouldForceDnsOnly(DnsZone $zone, string $host): bool
    {
        $zoneName = $this->normalizeHost($zone->name);
        $fqdn = str_ends_with($host, $zoneName) ? $host : "{$host}.{$zoneName}";

        return collect(['.ru', '.xn--p1ai', '.рф'])
            ->contains(fn (string $suffix) => str_ends_with($fqdn, $suffix));
    }

    private function message(int $recordCount, int $skippedCount): string
    {
        if ($recordCount === 0 && $skippedCount > 0) {
            return 'No matching Cloudflare zone was found for the configured domain(s).';
        }

        if ($skippedCount > 0) {
            return "{$recordCount} DNS record(s) configured. {$skippedCount} domain(s) did not match a connected Cloudflare zone.";
        }

        return "{$recordCount} DNS record(s) configured in Cloudflare.";
    }

    private function result(array $records = [], array $skipped = [], string $message = ''): array
    {
        return [
            'configured_count' => count($records),
            'skipped_count' => count($skipped),
            'records' => $records,
            'skipped' => $skipped,
            'message' => $message,
        ];
    }

    private function normalizeHost(string $host): string
    {
        $host = rtrim(trim($host), '.');

        return function_exists('mb_strtolower') ? mb_strtolower($host) : strtolower($host);
    }
}
