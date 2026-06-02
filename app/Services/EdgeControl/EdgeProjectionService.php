<?php

namespace App\Services\EdgeControl;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;
use App\Models\EdgeProjectionVersion;
use Illuminate\Support\Facades\Schema;

class EdgeProjectionService
{
    public function generateDnsRecordProjection(DnsRecord $record, string $adapter = EdgeProjection::ADAPTER_CLOUDFLARE, array $metadata = []): ?EdgeProjection
    {
        $record->loadMissing('zone');
        $intent = [
            'record_uuid' => $record->uuid,
            'zone_uuid' => $record->zone?->uuid,
            'type' => $record->type,
            'name' => $record->name,
            'content' => $record->content,
            'ttl' => $record->ttl,
            'proxied' => $record->proxied,
        ];
        $payload = [
            'adapter' => $adapter,
            'zone' => [
                'uuid' => $record->zone?->uuid,
                'name' => $record->zone?->name,
                'provider' => $record->zone?->provider,
            ],
            'record' => $intent,
        ];

        return $this->upsertProjection(
            teamId: (int) $record->zone?->team_id,
            domain: $record->name,
            intentType: 'dns_record',
            intentUuid: $record->uuid,
            intentVersion: (int) data_get($record->metadata, 'intent_version', 1),
            intentPayload: $intent,
            projectionType: EdgeProjection::TYPE_DNS_RECORD,
            adapter: $adapter,
            payload: $payload,
            requiredCapabilities: $this->requiredCapabilitiesForAdapter($adapter),
            metadata: array_merge(['source' => 'dns_record'], $metadata),
        );
    }

    public function generateEdgeRuntimeProjection(array $domainEntry, string $adapter = EdgeProjection::ADAPTER_TRAEFIK, array $metadata = []): ?EdgeProjection
    {
        $domain = (string) data_get($domainEntry, 'domain');
        $teamId = (int) data_get($metadata, 'team_id');
        $uses = collect(data_get($domainEntry, 'uses', []))
            ->map(fn (array $use): array => [
                'resource_type' => data_get($use, 'resource_type'),
                'resource_uuid' => data_get($use, 'resource_uuid'),
                'resource_name' => data_get($use, 'resource_name'),
                'project_name' => data_get($use, 'project_name'),
                'environment_name' => data_get($use, 'environment_name'),
            ])
            ->values()
            ->all();
        $intent = [
            'domain' => $domain,
            'uses' => $uses,
            'edge_policy' => data_get($domainEntry, 'edge_policy'),
            'dns_steering' => data_get($domainEntry, 'dns_steering'),
        ];
        $payload = [
            'adapter' => $adapter,
            'host' => $domain,
            'routes' => $uses,
            'edge_policy' => data_get($domainEntry, 'edge_policy'),
        ];

        return $this->upsertProjection(
            teamId: $teamId,
            domain: $domain,
            intentType: 'domain_edge_policy',
            intentUuid: data_get($metadata, 'intent_uuid') ?: $domain,
            intentVersion: (int) data_get($metadata, 'intent_version', 1),
            intentPayload: $intent,
            projectionType: EdgeProjection::TYPE_EDGE_RUNTIME,
            adapter: $adapter,
            payload: $payload,
            requiredCapabilities: $this->requiredCapabilitiesForAdapter($adapter),
            metadata: array_merge(['source' => 'domain_inventory'], $metadata),
        );
    }

    public function generateAuthoritativeDnsProjection(DnsZone $zone, array $metadata = []): ?EdgeProjection
    {
        $zone->loadMissing('records');
        $records = $zone->records
            ->map(fn (DnsRecord $record): array => [
                'uuid' => $record->uuid,
                'type' => $record->type,
                'name' => $record->name,
                'content' => $record->content,
                'ttl' => $record->ttl,
            ])
            ->values()
            ->all();
        $intent = [
            'zone_uuid' => $zone->uuid,
            'zone_name' => $zone->name,
            'records' => $records,
            'authoritative' => $zone->authoritativeEnabled(),
        ];
        $payload = [
            'adapter' => EdgeProjection::ADAPTER_AUTHORITATIVE_NS,
            'zone' => [
                'uuid' => $zone->uuid,
                'name' => $zone->name,
                'serial' => data_get($zone->metadata, 'serial', now()->format('YmdHis')),
            ],
            'records' => $records,
            'nameservers' => data_get($zone->metadata, 'nameservers', []),
        ];

        return $this->upsertProjection(
            teamId: $zone->team_id,
            domain: $zone->name,
            intentType: 'dns_zone',
            intentUuid: $zone->uuid,
            intentVersion: (int) data_get($zone->metadata, 'intent_version', 1),
            intentPayload: $intent,
            projectionType: EdgeProjection::TYPE_DNS_RECORD,
            adapter: EdgeProjection::ADAPTER_AUTHORITATIVE_NS,
            payload: $payload,
            requiredCapabilities: $this->requiredCapabilitiesForAdapter(EdgeProjection::ADAPTER_AUTHORITATIVE_NS),
            metadata: array_merge(['source' => 'dns_zone'], $metadata),
        );
    }

    public function recordControlAction(EdgeProjection $projection, string $actionType, array $request, array $outcome, string $status = EdgeControlAction::STATUS_SUCCEEDED, array $metadata = []): ?EdgeControlAction
    {
        if (! Schema::hasTable('edge_control_actions')) {
            return null;
        }

        $action = EdgeControlAction::create([
            'team_id' => $projection->team_id,
            'edge_projection_id' => $projection->id,
            'domain' => $projection->domain,
            'action_type' => $actionType,
            'adapter' => $projection->adapter,
            'status' => $status,
            'request' => $request,
            'outcome' => $outcome,
            'projection_hash' => $projection->projection_hash,
            'applied_projection_hash' => $status === EdgeControlAction::STATUS_SUCCEEDED ? $projection->projection_hash : null,
            'metadata' => array_merge(['schema' => 'edge.control_action.v1'], $metadata),
            'executed_at' => now(),
        ]);

        if ($status === EdgeControlAction::STATUS_SUCCEEDED) {
            $projection->update([
                'status' => EdgeProjection::STATUS_APPLIED,
                'applied_at' => $action->executed_at,
                'applied_projection_hash' => $projection->projection_hash,
            ]);
        }

        return $action;
    }

    public function requiredCapabilitiesForAdapter(string $adapter): array
    {
        return match ($adapter) {
            EdgeProjection::ADAPTER_TRAEFIK => ['edge_runtime'],
            EdgeProjection::ADAPTER_AUTHORITATIVE_NS => ['authoritative_dns'],
            default => [],
        };
    }

    public function hashPayload(array $payload): string
    {
        return hash('sha256', $this->canonicalJson($payload));
    }

    private function upsertProjection(int $teamId, ?string $domain, string $intentType, ?string $intentUuid, int $intentVersion, array $intentPayload, string $projectionType, string $adapter, array $payload, array $requiredCapabilities = [], array $metadata = []): ?EdgeProjection
    {
        if (! Schema::hasTable('edge_projections')) {
            return null;
        }

        $intentHash = $this->hashPayload($intentPayload);
        $projectionHash = $this->hashPayload($payload);
        $projection = EdgeProjection::query()
            ->where('team_id', $teamId)
            ->where('intent_type', $intentType)
            ->where('intent_uuid', $intentUuid)
            ->where('projection_type', $projectionType)
            ->where('adapter', $adapter)
            ->first();
        $version = $projection ? $projection->projection_version : 0;

        if (! $projection || $projection->projection_hash !== $projectionHash || $projection->intent_hash !== $intentHash) {
            $version++;
        }

        $projection = EdgeProjection::updateOrCreate(
            [
                'team_id' => $teamId,
                'intent_type' => $intentType,
                'intent_uuid' => $intentUuid,
                'projection_type' => $projectionType,
                'adapter' => $adapter,
            ],
            [
                'domain' => $domain,
                'intent_version' => $intentVersion,
                'intent_hash' => $intentHash,
                'projection_version' => max($version, 1),
                'projection_hash' => $projectionHash,
                'payload' => $payload,
                'required_capabilities' => $requiredCapabilities,
                'status' => EdgeProjection::STATUS_GENERATED,
                'intent_drift_status' => $projection?->applied_projection_hash && $projection->applied_projection_hash !== $projectionHash
                    ? EdgeProjection::INTENT_DRIFT_PENDING_APPLY
                    : EdgeProjection::INTENT_DRIFT_NONE,
                'conflict_policy' => EdgeProjection::CONFLICT_APPEND_ONLY_NO_ROLLBACK,
                'generated_at' => now(),
                'metadata' => array_merge(['schema' => 'edge.projection.v1'], $metadata),
            ],
        );

        if (Schema::hasTable('edge_projection_versions') && ! EdgeProjectionVersion::query()
            ->where('edge_projection_id', $projection->id)
            ->where('projection_version', $projection->projection_version)
            ->exists()) {
            EdgeProjectionVersion::create([
                'team_id' => $teamId,
                'edge_projection_id' => $projection->id,
                'projection_version' => $projection->projection_version,
                'intent_hash' => $intentHash,
                'projection_hash' => $projectionHash,
                'payload' => $payload,
                'metadata' => array_merge(['schema' => 'edge.projection_version.v1'], $metadata),
                'generated_at' => $projection->generated_at,
            ]);
        }

        return $projection->refresh();
    }

    private function canonicalJson(array $payload): string
    {
        return json_encode($this->sortKeys($payload), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->sortKeys($item), $value);
    }
}
