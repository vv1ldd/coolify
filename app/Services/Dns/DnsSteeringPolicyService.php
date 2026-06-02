<?php

namespace App\Services\Dns;

use App\Models\DnsRecord;
use App\Models\DnsSteeringPolicy;
use App\Models\DnsZone;
use App\Models\Server;
use App\Services\ControlPlane\ControlPlaneSyncService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class DnsSteeringPolicyService
{
    public function planForPolicy(DnsSteeringPolicy $policy, ?array $controlPlaneObservations = null): array
    {
        $zone = $policy->zone;
        $warnings = [];

        if (! $zone) {
            return $this->emptyPlan($policy, ['DNS steering policy is not attached to a DNS zone.']);
        }

        $recordName = $this->normalizeRecordName($zone, $policy->record_name ?: $policy->domain);
        $candidateNodes = $this->candidateNodes($policy, $controlPlaneObservations);
        $healthyNodes = $candidateNodes->filter(fn (array $node): bool => (bool) $node['healthy'])->values();
        $selectedNodes = $this->selectNodes($policy, $healthyNodes);

        if ($candidateNodes->isEmpty()) {
            $warnings[] = 'No candidate nodes are configured.';
        } elseif ($healthyNodes->isEmpty()) {
            $warnings[] = 'No healthy candidate nodes are available.';
        }

        if ((int) data_get($policy->metadata, 'record_limit', 1) > 1) {
            $warnings[] = 'Only one A and one AAAA target are planned in this MVP because managed DNS records are unique by zone, type and name.';
        }

        $desiredRecords = $this->desiredRecords($policy, $recordName, $selectedNodes);
        $currentRecords = $this->currentRecords($zone, $recordName);
        $actions = $this->diffRecords($policy, $desiredRecords, $currentRecords);
        $hasConflict = $actions->contains(fn (array $action): bool => $action['action'] === 'conflict');
        $reasons = $this->planReasons($policy, $candidateNodes, $selectedNodes, $actions);

        return [
            'policy_uuid' => $policy->uuid,
            'enabled' => (bool) $policy->enabled,
            'strategy' => $policy->strategy,
            'domain' => $this->normalizeHostname($policy->domain),
            'record_name' => $recordName,
            'candidate_nodes' => $candidateNodes->values()->all(),
            'selected_nodes' => $selectedNodes->values()->all(),
            'desired_records' => $desiredRecords->values()->all(),
            'current_records' => $currentRecords->values()->all(),
            'actions' => $actions->values()->all(),
            'reason' => $reasons[0],
            'reasons' => $reasons,
            'warnings' => $warnings,
            'can_apply' => (bool) $policy->enabled && ! $hasConflict && $desiredRecords->isNotEmpty(),
        ];
    }

    public function evaluatePolicies(DnsZoneService $dnsZones, bool $apply = false, ?int $teamId = null): array
    {
        $policies = DnsSteeringPolicy::query()
            ->with(['zone.records', 'application'])
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderBy('id')
            ->get();

        $result = [
            'dry_run' => ! $apply,
            'apply_requested' => $apply,
            'evaluated' => $policies->count(),
            'planned' => [],
            'applied' => [],
            'skipped' => [],
            'errors' => [],
        ];

        $observationsByTeam = [];

        foreach ($policies as $policy) {
            $observationsByTeam[$policy->team_id] ??= app(ControlPlaneSyncService::class)
                ->latestDnsSteeringObservationSource($policy->team_id);
            $plan = $this->planForPolicy($policy, $observationsByTeam[$policy->team_id]);
            $result['planned'][] = $plan;

            if (! $apply) {
                continue;
            }

            if (! $policy->enabled) {
                $result['skipped'][] = [
                    'policy_uuid' => $policy->uuid,
                    'reason' => 'policy_disabled',
                ];

                continue;
            }

            if (! data_get($plan, 'can_apply')) {
                $result['skipped'][] = [
                    'policy_uuid' => $policy->uuid,
                    'reason' => data_get($plan, 'reason', 'plan_not_safe'),
                ];

                continue;
            }

            try {
                $result['applied'][] = $this->applyPlan($policy, $dnsZones, $plan);
            } catch (\Throwable $e) {
                $result['errors'][] = [
                    'policy_uuid' => $policy->uuid,
                    'reason' => 'apply_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $result;
    }

    public function applyPlan(DnsSteeringPolicy $policy, DnsZoneService $dnsZones, ?array $plan = null): array
    {
        $plan ??= $this->planForPolicy($policy);

        if (! $policy->enabled) {
            throw ValidationException::withMessages([
                'enabled' => 'DNS steering policy must be enabled before applying DNS changes.',
            ]);
        }

        if (! data_get($plan, 'can_apply')) {
            throw ValidationException::withMessages([
                'plan' => 'DNS steering plan is not safe to apply.',
            ]);
        }

        $zone = $policy->zone;
        if (! $zone) {
            throw ValidationException::withMessages([
                'dns_zone_id' => 'DNS steering policy is not attached to a DNS zone.',
            ]);
        }

        $applied = [];
        foreach (data_get($plan, 'actions', []) as $action) {
            if (in_array($action['action'], ['create', 'update'], true)) {
                $record = $dnsZones->upsertManagedRecord($zone, array_merge($action['after'], [
                    'application_uuid' => $policy->application?->uuid,
                    'metadata' => array_merge((array) data_get($action, 'after.metadata', []), [
                        'dns_steering_policy_uuid' => $policy->uuid,
                        'dns_steering_strategy' => $policy->strategy,
                    ]),
                ]), $policy->team_id);

                $applied[] = [
                    'action' => $action['action'],
                    'record_uuid' => $record->uuid,
                    'type' => $record->type,
                    'name' => $record->name,
                    'content' => $record->content,
                ];
            }

            if ($action['action'] === 'delete') {
                $record = DnsRecord::whereUuid($action['record_uuid'])->first();
                if ($record && data_get($record->metadata, 'dns_steering_policy_uuid') === $policy->uuid) {
                    $dnsZones->deleteManagedRecord($record);
                    $applied[] = $action;
                }
            }
        }

        $policy->update([
            'desired_records' => data_get($plan, 'desired_records', []),
            'last_applied_at' => now(),
        ]);

        return [
            'policy_uuid' => $policy->uuid,
            'applied' => $applied,
        ];
    }

    private function emptyPlan(DnsSteeringPolicy $policy, array $warnings = []): array
    {
        return [
            'policy_uuid' => $policy->uuid,
            'enabled' => (bool) $policy->enabled,
            'strategy' => $policy->strategy,
            'domain' => $this->normalizeHostname($policy->domain),
            'record_name' => null,
            'candidate_nodes' => [],
            'selected_nodes' => [],
            'desired_records' => [],
            'current_records' => [],
            'actions' => [],
            'reason' => 'no_healthy_candidates',
            'reasons' => ['no_healthy_candidates'],
            'warnings' => $warnings,
            'can_apply' => false,
        ];
    }

    private function candidateNodes(DnsSteeringPolicy $policy, ?array $controlPlaneObservations = null): Collection
    {
        $candidates = collect($policy->candidate_nodes ?: [])
            ->filter(fn (mixed $node): bool => is_array($node))
            ->values();

        $serverUuids = $candidates
            ->map(fn (array $node): ?string => data_get($node, 'server_uuid') ?: data_get($node, 'uuid'))
            ->filter()
            ->unique()
            ->values();

        $servers = $serverUuids->isEmpty()
            ? collect()
            : Server::query()
                ->whereTeamId($policy->team_id)
                ->whereIn('uuid', $serverUuids)
                ->with('settings')
                ->get()
                ->keyBy('uuid');

        return $candidates
            ->map(function (array $node, int $index) use ($policy, $servers, $controlPlaneObservations): array {
                $serverUuid = data_get($node, 'server_uuid') ?: data_get($node, 'uuid');
                $server = $serverUuid ? $servers->get($serverUuid) : null;
                $configuredHealthy = data_get($node, 'healthy', true);
                $serverHealthy = $server ? $this->serverHealthy($server) : true;
                $metadataOverride = $this->metadataHealthOverride($policy, $node, $server);
                $observationOverride = $this->controlPlaneHealthOverride($controlPlaneObservations, $node, $server);
                $httpOverride = $this->httpHealthOverride($policy, $node);
                $healthy = is_bool($metadataOverride)
                    ? $metadataOverride
                    : (
                        is_bool($observationOverride)
                            ? $observationOverride
                            : (
                                is_bool($httpOverride)
                                    ? $httpOverride
                                    : (bool) $configuredHealthy && $serverHealthy
                            )
                    );

                return [
                    'server_uuid' => $serverUuid,
                    'name' => data_get($node, 'name') ?: $server?->name,
                    'ip' => data_get($node, 'ip') ?: data_get($node, 'ipv4') ?: $server?->ip,
                    'ipv6' => data_get($node, 'ipv6'),
                    'healthy' => $healthy,
                    'health_source' => $this->candidateHealthSource($metadataOverride, $observationOverride, $server, $httpOverride),
                    'weight' => max((int) data_get($node, 'weight', 1), 1),
                    'priority' => max((int) data_get($node, 'priority', $index + 1), 1),
                    'source' => $server ? 'server' : 'manual',
                ];
            })
            ->values();
    }

    private function metadataHealthOverride(DnsSteeringPolicy $policy, array $node, ?Server $server): ?bool
    {
        $overrides = data_get($policy->metadata, 'health_overrides', []);
        if (! is_array($overrides) || $overrides === []) {
            return null;
        }

        $keys = array_values(array_filter([
            data_get($node, 'server_uuid') ?: data_get($node, 'uuid'),
            $server?->uuid,
            data_get($node, 'name') ?: $server?->name,
            data_get($node, 'ip') ?: data_get($node, 'ipv4') ?: $server?->ip,
        ], fn (mixed $value): bool => filled($value)));

        foreach ($keys as $key) {
            if (array_key_exists((string) $key, $overrides)) {
                return (bool) $overrides[(string) $key];
            }
        }

        return null;
    }

    private function controlPlaneHealthOverride(?array $observations, array $node, ?Server $server): ?bool
    {
        if (! is_array($observations)) {
            return null;
        }

        $health = data_get($observations, 'health', []);
        if (! is_array($health) || $health === []) {
            return null;
        }

        $keys = array_values(array_filter([
            data_get($node, 'server_uuid') ?: data_get($node, 'uuid'),
            $server?->uuid,
            data_get($node, 'ip') ?: data_get($node, 'ipv4') ?: $server?->ip,
        ], fn (mixed $value): bool => filled($value)));

        foreach ($keys as $key) {
            if (array_key_exists((string) $key, $health) && array_key_exists('healthy', (array) $health[(string) $key])) {
                return (bool) $health[(string) $key]['healthy'];
            }
        }

        return null;
    }

    private function httpHealthOverride(DnsSteeringPolicy $policy, array $node): ?bool
    {
        $healthUrl = data_get($node, 'health_url');
        if (! filled($healthUrl)) {
            return null;
        }

        try {
            $request = Http::timeout(max((int) data_get($policy->metadata, 'health_timeout', 3), 1))
                ->acceptJson()
                ->withoutRedirecting();

            if (filled(data_get($node, 'health_host'))) {
                $request = $request->withHeaders([
                    'Host' => (string) data_get($node, 'health_host'),
                ]);
            }

            return $request
                ->get((string) $healthUrl)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function candidateHealthSource(?bool $metadataOverride, ?bool $observationOverride, ?Server $server, ?bool $httpOverride = null): string
    {
        if (is_bool($metadataOverride)) {
            return 'policy_metadata';
        }

        if (is_bool($observationOverride)) {
            return 'control_plane_observation';
        }

        if (is_bool($httpOverride)) {
            return 'http_health';
        }

        return $server ? 'server_settings' : 'candidate';
    }

    private function serverHealthy(Server $server): bool
    {
        return (bool) data_get($server, 'settings.is_reachable')
            && (bool) data_get($server, 'settings.is_usable')
            && ! (bool) data_get($server, 'settings.force_disabled')
            && ! (bool) data_get($server, 'settings.is_build_server')
            && (string) $server->ip !== '1.2.3.4';
    }

    private function desiredRecords(DnsSteeringPolicy $policy, string $recordName, Collection $nodes): Collection
    {
        $ttl = max((int) data_get($policy->metadata, 'ttl', 60), 1);
        $proxied = (bool) data_get($policy->metadata, 'proxied', false);

        return $nodes
            ->flatMap(function (array $node) use ($recordName, $ttl, $proxied, $policy): array {
                $records = [];
                foreach (['ip', 'ipv6'] as $field) {
                    $content = trim((string) data_get($node, $field, ''));
                    if ($content === '') {
                        continue;
                    }

                    $type = $this->recordTypeForIp($content);
                    if (! in_array($type, ['A', 'AAAA'], true)) {
                        continue;
                    }

                    $records[] = [
                        'type' => $type,
                        'name' => $recordName,
                        'content' => $content,
                        'ttl' => $ttl,
                        'proxied' => $proxied,
                        'comment' => 'Managed by Coolify DNS steering',
                        'metadata' => [
                            'dns_steering_policy_uuid' => $policy->uuid,
                            'dns_steering_strategy' => $policy->strategy,
                            'server_uuid' => data_get($node, 'server_uuid'),
                        ],
                    ];
                }

                return $records;
            })
            ->unique(fn (array $record): string => $record['type'].'|'.$record['name'].'|'.$record['content'])
            ->values();
    }

    private function selectNodes(DnsSteeringPolicy $policy, Collection $healthyNodes): Collection
    {
        if ($healthyNodes->isEmpty()) {
            return collect();
        }

        return match ($policy->strategy) {
            DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE => $healthyNodes
                ->sortBy(fn (array $node): string => $this->nodeSortKey($node))
                ->take(1)
                ->values(),
            DnsSteeringPolicy::STRATEGY_WEIGHTED_ROUND_ROBIN => $healthyNodes
                ->sortByDesc(fn (array $node): int => $node['weight'])
                ->take(1)
                ->values(),
            DnsSteeringPolicy::STRATEGY_HEALTH_BASED => $healthyNodes
                ->sortBy(fn (array $node): string => $this->nodeSortKey($node))
                ->take(1)
                ->values(),
            default => $healthyNodes->take(1)->values(),
        };
    }

    private function currentRecords(DnsZone $zone, string $recordName): Collection
    {
        return $zone->records()
            ->whereIn('type', ['A', 'AAAA'])
            ->where('name', $recordName)
            ->orderBy('type')
            ->get()
            ->map(fn (DnsRecord $record): array => [
                'record_uuid' => $record->uuid,
                'type' => strtoupper($record->type),
                'name' => $record->name,
                'content' => $record->content,
                'ttl' => $record->ttl,
                'proxied' => (bool) $record->proxied,
                'metadata' => $record->metadata ?: [],
            ])
            ->values();
    }

    private function nodeSortKey(array $node): string
    {
        return str_pad((string) $node['priority'], 10, '0', STR_PAD_LEFT).'|'.($node['name'] ?: $node['ip']);
    }

    private function planReasons(DnsSteeringPolicy $policy, Collection $candidateNodes, Collection $selectedNodes, Collection $actions): array
    {
        if ($candidateNodes->isNotEmpty() && $selectedNodes->isEmpty()) {
            return ['no_healthy_candidates'];
        }

        $hasMutableAction = $actions->contains(fn (array $action): bool => in_array($action['action'], ['create', 'update', 'delete', 'conflict'], true));
        if ($selectedNodes->isNotEmpty() && ! $hasMutableAction) {
            return ['no_change'];
        }

        $reasons = [];
        $primary = $this->primaryNode($policy, $candidateNodes);
        if ($primary && ! (bool) $primary['healthy'] && $selectedNodes->isNotEmpty()) {
            $reasons[] = 'primary_down';
        }

        if ($selectedNodes->isNotEmpty()) {
            $reasons[] = 'candidate_healthy';
        }

        if ($hasMutableAction) {
            $reasons[] = 'current_record_mismatch';
        }

        return array_values(array_unique($reasons ?: ['no_change']));
    }

    private function primaryNode(DnsSteeringPolicy $policy, Collection $candidateNodes): ?array
    {
        if ($candidateNodes->isEmpty()) {
            return null;
        }

        if (in_array($policy->strategy, [DnsSteeringPolicy::STRATEGY_ACTIVE_PASSIVE, DnsSteeringPolicy::STRATEGY_HEALTH_BASED], true)) {
            return $candidateNodes
                ->sortBy(fn (array $node): string => $this->nodeSortKey($node))
                ->first();
        }

        return $candidateNodes->first();
    }

    private function diffRecords(DnsSteeringPolicy $policy, Collection $desiredRecords, Collection $currentRecords): Collection
    {
        $actions = collect();
        $desiredKeys = $desiredRecords->map(fn (array $record): string => $record['type'].'|'.$record['name'])->all();

        foreach ($desiredRecords as $desired) {
            $current = $currentRecords->first(fn (array $record): bool => $record['type'] === $desired['type'] && $record['name'] === $desired['name']);

            if (! $current) {
                $actions->push([
                    'action' => 'create',
                    'type' => $desired['type'],
                    'name' => $desired['name'],
                    'before' => null,
                    'after' => $desired,
                ]);

                continue;
            }

            $changed = $current['content'] !== $desired['content']
                || (int) $current['ttl'] !== (int) $desired['ttl']
                || (bool) $current['proxied'] !== (bool) $desired['proxied'];

            $actions->push([
                'action' => $changed ? 'update' : 'noop',
                'record_uuid' => $current['record_uuid'],
                'type' => $desired['type'],
                'name' => $desired['name'],
                'before' => $current,
                'after' => $desired,
            ]);
        }

        foreach ($currentRecords as $current) {
            $key = $current['type'].'|'.$current['name'];
            if (in_array($key, $desiredKeys, true)) {
                continue;
            }

            if (data_get($current, 'metadata.dns_steering_policy_uuid') === $policy->uuid) {
                $actions->push([
                    'action' => 'delete',
                    'record_uuid' => $current['record_uuid'],
                    'type' => $current['type'],
                    'name' => $current['name'],
                    'before' => $current,
                    'after' => null,
                ]);

                continue;
            }

            $actions->push([
                'action' => 'conflict',
                'record_uuid' => $current['record_uuid'],
                'type' => $current['type'],
                'name' => $current['name'],
                'before' => $current,
                'after' => null,
                'reason' => 'Existing unmanaged A/AAAA record would be left untouched.',
            ]);
        }

        return $actions;
    }

    private function normalizeRecordName(DnsZone $zone, string $name): string
    {
        $name = $this->normalizeHostname($name);

        return $name === '@' ? $zone->name : $name;
    }

    private function normalizeHostname(string $hostname): string
    {
        $hostname = rtrim(trim($hostname), '.');

        return function_exists('mb_strtolower') ? mb_strtolower($hostname) : strtolower($hostname);
    }

    private function recordTypeForIp(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'A';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'AAAA';
        }

        return null;
    }
}
