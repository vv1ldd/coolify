<?php

namespace App\Services\Dns;

use App\Models\Application;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Validation\ValidationException;

class DnsZoneService
{
    public function createZone(int $teamId, array $data): DnsZone
    {
        $provider = strtolower((string) data_get($data, 'provider', 'cloudflare'));
        if ($provider !== 'cloudflare') {
            throw ValidationException::withMessages(['provider' => 'Only Cloudflare DNS zones are supported in this MVP.']);
        }

        $zoneId = data_get($data, 'provider_zone_id') ?: $this->findCloudflareZoneId(
            token: (string) data_get($data, 'api_token'),
            name: (string) data_get($data, 'name'),
        );

        return DnsZone::create([
            'team_id' => $teamId,
            'provider' => $provider,
            'name' => $this->normalizeHostname((string) data_get($data, 'name')),
            'provider_zone_id' => $zoneId,
            'api_token' => (string) data_get($data, 'api_token'),
        ]);
    }

    public function updateZone(DnsZone $zone, array $data): DnsZone
    {
        $updates = [];
        foreach (['name', 'provider_zone_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $updates[$field] = $field === 'name'
                    ? $this->normalizeHostname((string) $data[$field])
                    : $data[$field];
            }
        }

        if (filled(data_get($data, 'api_token'))) {
            $updates['api_token'] = (string) data_get($data, 'api_token');
        }

        $zone->update($updates);

        return $zone->refresh();
    }

    public function listProviderZones(DnsZone $zone): array
    {
        return $this->provider($zone)->listZones();
    }

    public function listProviderRecords(DnsZone $zone, ?string $type = null, ?string $name = null): array
    {
        $this->ensureProviderZoneId($zone);

        return $this->provider($zone)->listRecords(
            zoneId: $zone->provider_zone_id,
            type: $type,
            name: $name ? $this->normalizeRecordName($zone, $name) : null,
        );
    }

    public function upsertManagedRecord(DnsZone $zone, array $data, int $teamId): DnsRecord
    {
        $this->ensureProviderZoneId($zone);

        $record = $this->normalizeRecordData($zone, $data);
        $applicationId = $this->applicationIdForTeam(data_get($data, 'application_uuid'), $teamId);
        $existing = DnsRecord::where('dns_zone_id', $zone->id)
            ->where('type', $record['type'])
            ->where('name', $record['name'])
            ->first();

        $providerRecord = $this->provider($zone)->upsertRecord(
            zoneId: $zone->provider_zone_id,
            record: $record,
            recordId: $existing?->provider_record_id,
        );

        return DnsRecord::updateOrCreate(
            [
                'dns_zone_id' => $zone->id,
                'type' => $record['type'],
                'name' => $record['name'],
            ],
            [
                'application_id' => $applicationId,
                'provider_record_id' => data_get($providerRecord, 'id'),
                'content' => $record['content'],
                'ttl' => $record['ttl'],
                'proxied' => $record['proxied'],
                'comment' => data_get($record, 'comment'),
                'metadata' => array_merge([
                    'provider' => 'cloudflare',
                    'dns_only_forced' => data_get($record, 'dns_only_forced', false),
                ], (array) data_get($data, 'metadata', [])),
            ],
        )->refresh();
    }

    public function deleteManagedRecord(DnsRecord $record): void
    {
        $zone = $record->zone;
        if ($record->provider_record_id && $zone?->provider_zone_id) {
            $this->provider($zone)->deleteRecord($zone->provider_zone_id, $record->provider_record_id);
        }

        $record->delete();
    }

    private function findCloudflareZoneId(string $token, string $name): string
    {
        $normalizedName = $this->normalizeHostname($name);
        $zone = collect((new CloudflareDnsProvider($token))->listZones())
            ->first(fn (array $zone) => $this->normalizeHostname((string) data_get($zone, 'name')) === $normalizedName);

        if (! $zone) {
            throw ValidationException::withMessages([
                'provider_zone_id' => 'Cloudflare zone was not found by name. Provide provider_zone_id explicitly or check token permissions.',
            ]);
        }

        return (string) data_get($zone, 'id');
    }

    private function provider(DnsZone $zone): CloudflareDnsProvider
    {
        if ($zone->provider !== 'cloudflare') {
            throw ValidationException::withMessages(['provider' => 'Only Cloudflare DNS zones are supported in this MVP.']);
        }

        return new CloudflareDnsProvider($zone->api_token);
    }

    private function ensureProviderZoneId(DnsZone $zone): void
    {
        if (blank($zone->provider_zone_id)) {
            throw ValidationException::withMessages([
                'provider_zone_id' => 'DNS zone is missing the provider zone id.',
            ]);
        }
    }

    private function normalizeRecordData(DnsZone $zone, array $data): array
    {
        $type = strtoupper((string) data_get($data, 'type'));
        $name = $this->normalizeRecordName($zone, (string) data_get($data, 'name'));
        $proxied = (bool) data_get($data, 'proxied', false);
        $dnsOnlyForced = $this->shouldForceDnsOnly($zone, $name);

        if ($dnsOnlyForced) {
            $proxied = false;
        }

        return [
            'type' => $type,
            'name' => $name,
            'content' => trim((string) data_get($data, 'content')),
            'ttl' => max((int) data_get($data, 'ttl', 1), 1),
            'proxied' => $proxied,
            'comment' => data_get($data, 'comment'),
            'dns_only_forced' => $dnsOnlyForced,
        ];
    }

    private function applicationIdForTeam(?string $applicationUuid, int $teamId): ?int
    {
        if (blank($applicationUuid)) {
            return null;
        }

        $application = Application::where('uuid', $applicationUuid)
            ->whereRelation('environment.project', 'team_id', $teamId)
            ->first();

        if (! $application) {
            throw ValidationException::withMessages([
                'application_uuid' => 'Application was not found for the current team.',
            ]);
        }

        return $application->id;
    }

    private function normalizeRecordName(DnsZone $zone, string $name): string
    {
        $name = $this->normalizeHostname($name);

        return $name === '@' ? $zone->name : $name;
    }

    private function normalizeHostname(string $hostname): string
    {
        return rtrim($this->lower(trim($hostname)), '.');
    }

    private function shouldForceDnsOnly(DnsZone $zone, string $recordName): bool
    {
        $hostname = $this->normalizeHostname($recordName);
        $zoneName = $this->normalizeHostname($zone->name);
        $fqdn = str_ends_with($hostname, $zoneName) ? $hostname : "{$hostname}.{$zoneName}";

        return collect(['.ru', '.xn--p1ai', '.рф'])
            ->contains(fn (string $suffix) => str_ends_with($fqdn, $suffix));
    }

    private function lower(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}
