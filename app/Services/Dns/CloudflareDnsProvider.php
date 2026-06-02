<?php

namespace App\Services\Dns;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class CloudflareDnsProvider
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function __construct(private readonly string $apiToken) {}

    public function listZones(): array
    {
        return $this->request('get', '/zones');
    }

    public function listRecords(string $zoneId, ?string $type = null, ?string $name = null): array
    {
        $query = array_filter([
            'type' => $type ? strtoupper($type) : null,
            'name' => $name,
        ]);

        return $this->request('get', "/zones/{$zoneId}/dns_records", $query);
    }

    public function upsertRecord(string $zoneId, array $record, ?string $recordId = null): array
    {
        $payload = $this->recordPayload($record);
        $recordId ??= $this->existingRecordId($zoneId, $payload['type'], $payload['name']);

        if ($recordId) {
            return $this->request('put', "/zones/{$zoneId}/dns_records/{$recordId}", $payload);
        }

        return $this->request('post', "/zones/{$zoneId}/dns_records", $payload);
    }

    public function deleteRecord(string $zoneId, string $recordId): array
    {
        return $this->request('delete', "/zones/{$zoneId}/dns_records/{$recordId}");
    }

    private function existingRecordId(string $zoneId, string $type, string $name): ?string
    {
        $records = $this->listRecords($zoneId, $type, $name);
        $record = collect($records)->first(fn (array $record) => data_get($record, 'type') === $type && data_get($record, 'name') === $name);

        return data_get($record, 'id');
    }

    private function recordPayload(array $record): array
    {
        $type = strtoupper((string) data_get($record, 'type'));
        $payload = [
            'type' => $type,
            'name' => (string) data_get($record, 'name'),
            'content' => (string) data_get($record, 'content'),
            'ttl' => (int) data_get($record, 'ttl', 1),
        ];

        if (in_array($type, ['A', 'AAAA', 'CNAME'], true)) {
            $payload['proxied'] = (bool) data_get($record, 'proxied', false);
        }

        if (filled(data_get($record, 'comment'))) {
            $payload['comment'] = (string) data_get($record, 'comment');
        }

        return $payload;
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $pendingRequest = Http::withToken($this->apiToken)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->baseUrl(self::BASE_URL);

        $response = match ($method) {
            'get' => $pendingRequest->get($path, $payload),
            'post' => $pendingRequest->post($path, $payload),
            'put' => $pendingRequest->put($path, $payload),
            'delete' => $pendingRequest->delete($path, $payload),
            default => throw new CloudflareDnsException('Unsupported Cloudflare DNS operation.'),
        };

        $body = $response->json();
        if ($response->failed() || data_get($body, 'success') === false) {
            throw new CloudflareDnsException($this->errorMessage($body, $response->status()));
        }

        return Arr::wrap(data_get($body, 'result'));
    }

    private function errorMessage(?array $body, int $status): string
    {
        $message = collect(data_get($body, 'errors', []))
            ->map(fn (array $error) => data_get($error, 'message'))
            ->filter()
            ->join('; ');

        return $message ?: "Cloudflare DNS API request failed with status {$status}.";
    }
}
