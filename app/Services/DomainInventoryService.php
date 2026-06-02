<?php

namespace App\Services;

use App\Models\Application;
use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\ServiceApplication;
use App\Services\EdgeProtection\EdgePolicyService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class DomainInventoryService
{
    public function forTeam(int $teamId, ?string $search = null): Collection
    {
        $zones = $this->dnsZones($teamId);
        $steeringPolicies = $this->dnsSteeringPolicies($teamId);
        $uses = collect()
            ->merge($this->applicationUses($teamId))
            ->merge($this->serviceApplicationUses($teamId));

        $records = $this->dnsRecords($teamId);

        $entries = $uses
            ->groupBy('domain')
            ->map(fn (Collection $domainUses, string $domain): array => $this->entryForDomain($teamId, $domain, $domainUses, $records->get($domain, collect()), $zones, $steeringPolicies->get($domain, collect())))
            ->values();

        $recordOnlyEntries = $records
            ->reject(fn (Collection $domainRecords, string $domain): bool => $uses->contains('domain', $domain))
            ->map(fn (Collection $domainRecords, string $domain): array => $this->entryForDomain($teamId, $domain, collect(), $domainRecords, $zones, $steeringPolicies->get($domain, collect())))
            ->values();

        $entries = $entries
            ->merge($recordOnlyEntries)
            ->sortBy('domain')
            ->values();

        if (blank($search)) {
            return $entries;
        }

        $needle = $this->normalizeSearch($search);

        return $entries
            ->filter(fn (array $entry): bool => str_contains($this->searchText($entry), $needle))
            ->values();
    }

    private function applicationUses(int $teamId): Collection
    {
        return Application::query()
            ->whereRelation('environment.project.team', 'id', $teamId)
            ->with(['environment.project'])
            ->orderBy('name')
            ->get()
            ->flatMap(function (Application $application): Collection {
                $uses = $this->splitDomainList($application->fqdn)
                    ->map(fn (array $domain): array => $this->resourceUse(
                        domain: $domain['host'],
                        rawDomain: $domain['raw'],
                        source: 'App',
                        resourceType: 'application',
                        resourceName: $application->name,
                        resourceUuid: $application->uuid,
                        projectName: data_get($application, 'environment.project.name'),
                        environmentName: data_get($application, 'environment.name'),
                        resourceLink: $application->link(),
                        labels: $this->labelLines($application->custom_labels),
                        expectedTargets: $this->expectedTargetsForApplication($application),
                    ));

                return $uses->merge($this->composeUses($application));
            });
    }

    private function serviceApplicationUses(int $teamId): Collection
    {
        return ServiceApplication::query()
            ->whereRelation('service.environment.project.team', 'id', $teamId)
            ->with(['service.environment.project'])
            ->orderBy('name')
            ->get()
            ->flatMap(fn (ServiceApplication $serviceApplication): Collection => $this->splitDomainList($serviceApplication->fqdn)
                ->map(fn (array $domain): array => $this->resourceUse(
                    domain: $domain['host'],
                    rawDomain: $domain['raw'],
                    source: 'Service app',
                    resourceType: 'service_application',
                    resourceName: $serviceApplication->human_name ?: $serviceApplication->name,
                    resourceUuid: $serviceApplication->uuid,
                    projectName: data_get($serviceApplication, 'service.environment.project.name'),
                    environmentName: data_get($serviceApplication, 'service.environment.name'),
                    resourceLink: data_get($serviceApplication, 'service')->link(),
                    serviceName: data_get($serviceApplication, 'service.name'),
                    containerName: $serviceApplication->name,
                    labels: collect(),
                    expectedTargets: $this->expectedTargetsForServiceApplication($serviceApplication),
                )));
    }

    private function composeUses(Application $application): Collection
    {
        if (blank($application->docker_compose_domains)) {
            return collect();
        }

        $domainsByService = json_decode($application->docker_compose_domains, true);
        if (! is_array($domainsByService)) {
            return collect();
        }

        return collect($domainsByService)
            ->flatMap(function (mixed $service, string|int $serviceName) use ($application): Collection {
                $domainList = is_array($service) ? data_get($service, 'domain') : $service;

                return $this->splitDomainList(is_string($domainList) ? $domainList : null)
                    ->map(fn (array $domain): array => $this->resourceUse(
                        domain: $domain['host'],
                        rawDomain: $domain['raw'],
                        source: 'Docker Compose service',
                        resourceType: 'application',
                        resourceName: $application->name,
                        resourceUuid: $application->uuid,
                        projectName: data_get($application, 'environment.project.name'),
                        environmentName: data_get($application, 'environment.name'),
                        resourceLink: $application->link(),
                        serviceName: (string) $serviceName,
                        containerName: (string) $serviceName,
                        labels: $this->labelLines($application->custom_labels),
                        expectedTargets: $this->expectedTargetsForApplication($application),
                    ));
            });
    }

    private function dnsRecords(int $teamId): Collection
    {
        return DnsRecord::query()
            ->whereRelation('zone', 'team_id', $teamId)
            ->with(['zone', 'application.environment.project'])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->map(function (DnsRecord $record): array {
                $zoneName = $this->normalizeHost((string) data_get($record, 'zone.name'));
                $name = $this->normalizeDnsRecordName($record->name, $zoneName);

                return [
                    'domain' => $name,
                    'record_uuid' => $record->uuid,
                    'zone_name' => data_get($record, 'zone.name'),
                    'zone_uuid' => data_get($record, 'zone.uuid'),
                    'type' => strtoupper($record->type),
                    'name' => $record->name,
                    'content' => $record->content,
                    'ttl' => $record->ttl,
                    'proxied' => (bool) $record->proxied,
                    'proxy_status' => $record->proxied ? 'Proxied' : 'DNS-only',
                    'application_uuid' => data_get($record, 'application.uuid'),
                    'application_uuid_short' => $this->shortUuid(data_get($record, 'application.uuid')),
                    'application_name' => data_get($record, 'application.name'),
                    'project_name' => data_get($record, 'application.environment.project.name'),
                    'environment_name' => data_get($record, 'application.environment.name'),
                ];
            })
            ->filter(fn (array $record): bool => filled($record['domain']))
            ->groupBy('domain');
    }

    private function dnsZones(int $teamId): Collection
    {
        return DnsZone::query()
            ->whereTeamId($teamId)
            ->orderBy('name')
            ->get(['uuid', 'name'])
            ->map(fn (DnsZone $zone): array => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'host' => $this->normalizeHost($zone->name),
            ]);
    }

    private function dnsSteeringPolicies(int $teamId): Collection
    {
        if (! Schema::hasTable('dns_steering_policies')) {
            return collect();
        }

        return DnsSteeringPolicy::query()
            ->whereTeamId($teamId)
            ->with(['zone', 'application'])
            ->orderByDesc('enabled')
            ->orderBy('domain')
            ->get()
            ->map(function (DnsSteeringPolicy $policy): array {
                $candidates = collect($policy->candidate_nodes ?: [])
                    ->filter(fn (mixed $node): bool => is_array($node))
                    ->map(fn (array $node): array => [
                        'server_uuid' => $this->shortUuid(data_get($node, 'server_uuid') ?: data_get($node, 'uuid')),
                        'name' => data_get($node, 'name'),
                        'ip' => data_get($node, 'ip') ?: data_get($node, 'ipv4') ?: data_get($node, 'ipv6'),
                        'healthy' => (bool) data_get($node, 'healthy', true),
                    ])
                    ->values();

                return [
                    'uuid' => $policy->uuid,
                    'domain' => $this->normalizeHost($policy->domain),
                    'strategy' => $policy->strategy,
                    'enabled' => (bool) $policy->enabled,
                    'zone_name' => data_get($policy, 'zone.name'),
                    'application_name' => data_get($policy, 'application.name'),
                    'candidate_count' => $candidates->count(),
                    'healthy_candidate_count' => $candidates->where('healthy', true)->count(),
                    'candidates' => $candidates,
                    'last_applied_at' => $policy->last_applied_at?->toIso8601String(),
                ];
            })
            ->groupBy('domain');
    }

    private function entryForDomain(int $teamId, string $domain, Collection $uses, Collection $records, Collection $zones, Collection $steeringPolicies): array
    {
        $resourceKeys = $uses
            ->map(fn (array $use): string => implode(':', array_filter([
                $use['resource_type'],
                $use['resource_uuid'],
                $use['service_name'],
                $use['container_name'],
                $use['source'],
            ])))
            ->unique()
            ->values();

        $expectedTargets = $uses
            ->pluck('expected_targets')
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        $unexpectedRecords = $records
            ->filter(fn (array $record): bool => in_array($record['type'], ['A', 'AAAA'], true)
                && $expectedTargets->isNotEmpty()
                && ! $expectedTargets->contains($record['content']));
        $edgePolicy = app(EdgePolicyService::class)->resolveByDomain($teamId, $domain);
        $steeringPolicy = $steeringPolicies->first();

        return [
            'domain' => $domain,
            'uses' => $uses->values(),
            'dns_records' => $records->values(),
            'dns_zones' => $this->zonesForDomain($domain, $records, $zones),
            'has_conflict' => $resourceKeys->count() > 1,
            'conflict_summary' => $resourceKeys->count() > 1 ? "{$resourceKeys->count()} resources use this domain" : null,
            'dns_unexpected' => $unexpectedRecords->isNotEmpty(),
            'dns_summary' => $records->isEmpty() ? 'No managed DNS record' : $records->count().' managed record(s)',
            'edge_policy' => [
                'mode' => $edgePolicy->mode,
                'name' => $edgePolicy->name,
                'source' => $edgePolicy->source,
                'challenge_enabled' => $edgePolicy->challengeEnabled,
                'silent_drop_enabled' => $edgePolicy->silentDropEnabled,
            ],
            'expected_targets' => $expectedTargets,
            'node_candidates' => $this->nodeCandidates($expectedTargets, $steeringPolicy),
            'dns_steering' => $steeringPolicy,
        ];
    }

    private function resourceUse(
        string $domain,
        string $rawDomain,
        string $source,
        string $resourceType,
        string $resourceName,
        string $resourceUuid,
        ?string $projectName,
        ?string $environmentName,
        ?string $resourceLink,
        ?string $serviceName = null,
        ?string $containerName = null,
        Collection $labels = new Collection,
        Collection $expectedTargets = new Collection,
    ): array {
        return [
            'domain' => $domain,
            'raw_domain' => $rawDomain,
            'source' => $source,
            'resource_type' => $resourceType,
            'resource_name' => $resourceName,
            'resource_uuid' => $this->shortUuid($resourceUuid),
            'project_name' => $projectName,
            'environment_name' => $environmentName,
            'resource_link' => $resourceLink,
            'service_name' => $serviceName,
            'container_name' => $containerName,
            'edge' => $this->edgeSummary($labels),
            'expected_targets' => $expectedTargets,
        ];
    }

    private function splitDomainList(?string $domains): Collection
    {
        return collect(explode(',', (string) $domains))
            ->map(fn (string $domain): string => trim($domain))
            ->filter()
            ->map(fn (string $domain): array => [
                'raw' => $domain,
                'host' => $this->hostFromDomain($domain),
            ])
            ->filter(fn (array $domain): bool => filled($domain['host']))
            ->unique('host')
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

    private function normalizeDnsRecordName(string $name, string $zoneName): string
    {
        $recordName = $this->normalizeHost($name);

        if ($recordName === '@') {
            return $zoneName;
        }

        if ($zoneName !== '' && ! str_ends_with($recordName, $zoneName)) {
            return "{$recordName}.{$zoneName}";
        }

        return $recordName;
    }

    private function normalizeHost(string $host): string
    {
        $host = rtrim(trim($host), '.');

        return function_exists('mb_strtolower') ? mb_strtolower($host) : strtolower($host);
    }

    private function shortUuid(?string $uuid): ?string
    {
        return $uuid ? str($uuid)->limit(8, '')->toString() : null;
    }

    private function zonesForDomain(string $domain, Collection $records, Collection $zones): Collection
    {
        $recordZones = $records
            ->map(fn (array $record): array => [
                'uuid' => $record['zone_uuid'],
                'name' => $record['zone_name'],
            ]);

        $matchedZones = $zones
            ->filter(fn (array $zone): bool => $domain === $zone['host'] || str_ends_with($domain, ".{$zone['host']}"))
            ->map(fn (array $zone): array => [
                'uuid' => $zone['uuid'],
                'name' => $zone['name'],
            ]);

        return $recordZones
            ->merge($matchedZones)
            ->filter(fn (array $zone): bool => filled($zone['uuid']) && filled($zone['name']))
            ->unique('uuid')
            ->sortBy('name')
            ->values();
    }

    private function nodeCandidates(Collection $expectedTargets, ?array $steeringPolicy): Collection
    {
        if ($steeringPolicy) {
            return collect(data_get($steeringPolicy, 'candidates', []))
                ->filter(fn (array $candidate): bool => filled(data_get($candidate, 'ip')))
                ->values();
        }

        return $expectedTargets
            ->map(fn (string $target): array => [
                'server_uuid' => null,
                'name' => null,
                'ip' => $target,
                'healthy' => null,
            ])
            ->values();
    }

    private function expectedTargetsForApplication(Application $application): Collection
    {
        return collect([data_get($application, 'destination.server.ip')])
            ->filter()
            ->values();
    }

    private function expectedTargetsForServiceApplication(ServiceApplication $serviceApplication): Collection
    {
        return collect([
            data_get($serviceApplication, 'service.destination.server.ip'),
            data_get($serviceApplication, 'service.server.ip'),
        ])
            ->filter()
            ->unique()
            ->values();
    }

    private function labelLines(?string $customLabels): Collection
    {
        if (blank($customLabels)) {
            return collect();
        }

        $decoded = base64_decode($customLabels, true);
        if ($decoded !== false && base64_encode($decoded) === $customLabels) {
            $customLabels = $decoded;
        }

        return collect(preg_split('/\r\n|\r|\n/', (string) $customLabels))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->values();
    }

    private function edgeSummary(Collection $labels): array
    {
        $edgeLabels = $labels
            ->filter(fn (string $label): bool => str_contains($label, 'coolify-edge'))
            ->values();

        if ($edgeLabels->isEmpty()) {
            if (data_get(traefikTrafficFilterSettings(), 'enabled', true)) {
                return [
                    'active' => true,
                    'summary' => 'settings enabled',
                ];
            }

            return [
                'active' => false,
                'summary' => 'not detected',
            ];
        }

        $average = $this->firstLabelMatch($edgeLabels, '/ratelimit\.average=(\d+)/');
        $burst = $this->firstLabelMatch($edgeLabels, '/ratelimit\.burst=(\d+)/');
        $inflight = $this->firstLabelMatch($edgeLabels, '/inflightreq\.amount=(\d+)/');

        $parts = collect();
        if ($average !== null) {
            $parts->push('rate '.$average.'/s'.($burst !== null ? ' burst '.$burst : ''));
        }
        if ($inflight !== null) {
            $parts->push('inflight '.$inflight);
        }
        if ($edgeLabels->contains(fn (string $label): bool => str_contains($label, '-headers.headers.'))) {
            $parts->push('headers');
        }

        return [
            'active' => true,
            'summary' => $parts->isEmpty() ? 'labels detected' : $parts->join(', '),
        ];
    }

    private function firstLabelMatch(Collection $labels, string $pattern): ?string
    {
        foreach ($labels as $label) {
            if (preg_match($pattern, $label, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    private function searchText(array $entry): string
    {
        $pieces = collect([
            $entry['domain'],
            $entry['dns_summary'],
            data_get($entry, 'edge_policy.mode'),
            data_get($entry, 'edge_policy.name'),
            data_get($entry, 'dns_steering.strategy'),
            data_get($entry, 'dns_steering.zone_name'),
            data_get($entry, 'dns_steering.application_name'),
        ]);

        collect($entry['uses'])->each(function (array $use) use ($pieces): void {
            $pieces->push($use['source']);
            $pieces->push($use['resource_type']);
            $pieces->push($use['resource_name']);
            $pieces->push($use['resource_uuid']);
            $pieces->push($use['project_name']);
            $pieces->push($use['environment_name']);
            $pieces->push($use['service_name']);
            $pieces->push($use['container_name']);
            $pieces->push(data_get($use, 'edge.summary'));
        });

        collect($entry['dns_records'])->each(function (array $record) use ($pieces): void {
            $pieces->push($record['zone_name']);
            $pieces->push($record['type']);
            $pieces->push($record['name']);
            $pieces->push($record['content']);
            $pieces->push($record['proxy_status']);
            $pieces->push($record['application_name']);
        });

        collect($entry['node_candidates'])->each(function (array $candidate) use ($pieces): void {
            $pieces->push(data_get($candidate, 'name'));
            $pieces->push(data_get($candidate, 'server_uuid'));
            $pieces->push(data_get($candidate, 'ip'));
        });

        return $this->normalizeSearch($pieces->filter()->join(' '));
    }

    private function normalizeSearch(?string $value): string
    {
        $value = trim((string) $value);

        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
