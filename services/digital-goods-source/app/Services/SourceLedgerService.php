<?php

namespace App\Services;

use App\Models\SourceLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class SourceLedgerService
{
    private const CONSTITUTION_ID = 'digital-goods-source-v1';

    private const DETERMINISM = 'provider-authority-v1';

    public function record(
        string $eventType,
        ?Model $entity = null,
        array $payload = [],
        ?array $inputState = null,
        ?array $outputState = null,
        ?string $scope = null,
        ?string $provider = null,
        ?string $partnerExternalId = null,
        ?string $reference = null,
    ): SourceLedgerEntry {
        $scope = $scope ?: $this->scopeFor($provider, $partnerExternalId);

        return DB::transaction(function () use (
            $eventType,
            $entity,
            $payload,
            $inputState,
            $outputState,
            $scope,
            $provider,
            $partnerExternalId,
            $reference,
        ): SourceLedgerEntry {
            $previousFingerprint = SourceLedgerEntry::query()
                ->where('scope', $scope)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->value('fingerprint');

            $occurredAt = now()->toDateTimeString();
            $document = [
                'prev' => $previousFingerprint,
                'scope' => $scope,
                'type' => $eventType,
                'entity_id' => (string) $entity?->getKey(),
                'entity_type' => $entity ? get_class($entity) : null,
                'provider' => $provider,
                'partner_external_id' => $partnerExternalId,
                'reference' => $reference,
                'payload' => $this->sanitizePayload($payload),
                'in' => $inputState,
                'out' => $outputState,
                'ts' => $occurredAt,
                'constitution' => self::CONSTITUTION_ID,
                'determinism' => self::DETERMINISM,
            ];

            return SourceLedgerEntry::create([
                'scope' => $scope,
                'event_type' => $eventType,
                'entity_type' => $entity ? get_class($entity) : null,
                'entity_id' => $entity?->getKey(),
                'provider' => $provider,
                'partner_external_id' => $partnerExternalId,
                'reference' => $reference,
                'payload' => $document['payload'],
                'input_state' => $inputState,
                'output_state' => $outputState,
                'fingerprint' => hash('sha256', $this->canonicalJson($document)),
                'previous_fingerprint' => $previousFingerprint,
                'meta' => [
                    'constitution' => self::CONSTITUTION_ID,
                    'determinism' => self::DETERMINISM,
                    'kernel_protocol_version' => config('digital-goods-source.kernel_protocol_version', 'v1'),
                    'provider_contract_version' => config('digital-goods-source.provider_contract_version', 'v1'),
                ],
                'occurred_at' => $occurredAt,
            ]);
        });
    }

    public function receipt(SourceLedgerEntry $entry): array
    {
        return [
            'ledger' => 'digital-goods-source',
            'event_type' => $entry->event_type,
            'event_hash' => $entry->fingerprint,
            'previous_hash' => $entry->previous_fingerprint,
            'scope' => $entry->scope,
            'provider' => $entry->provider,
            'partner_external_id' => $entry->partner_external_id,
            'reference' => $entry->reference,
            'kernel_protocol_version' => data_get($entry->meta, 'kernel_protocol_version', 'v1'),
            'provider_contract_version' => data_get($entry->meta, 'provider_contract_version', 'v1'),
            'occurred_at' => $entry->occurred_at?->toIso8601String(),
        ];
    }

    public function receiptForReference(string $reference, ?string $eventType = null): ?array
    {
        $entry = SourceLedgerEntry::query()
            ->where('reference', $reference)
            ->when($eventType, fn ($query) => $query->where('event_type', $eventType))
            ->orderByDesc('id')
            ->first();

        return $entry ? $this->receipt($entry) : null;
    }

    public function head(?string $scope = null): array
    {
        $query = SourceLedgerEntry::query()
            ->when($scope, fn ($query) => $query->where('scope', $scope));

        $entry = (clone $query)->orderByDesc('id')->first();

        return [
            'ledger' => 'digital-goods-source',
            'scope' => $scope ?: 'all',
            'head_hash' => $entry?->fingerprint,
            'head_id' => $entry?->id,
            'events_count' => (clone $query)->count(),
            'kernel_protocol_version' => config('digital-goods-source.kernel_protocol_version', 'v1'),
            'provider_contract_version' => config('digital-goods-source.provider_contract_version', 'v1'),
        ];
    }

    public function scopeFor(?string $provider = null, ?string $partnerExternalId = null): string
    {
        if ($partnerExternalId) {
            return 'partner:'.$partnerExternalId;
        }

        if ($provider) {
            return 'provider:'.$provider;
        }

        return 'source:global';
    }

    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = ['api_token', 'financial_secret', 'secret', 'secret_key', 'client_secret', 'pin', 'code', 'card_number'];

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->sanitizePayload($value);
            } elseif (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $payload[$key] = '***';
            }
        }

        return $payload;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode($this->sortCanonicalValue($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortCanonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
        }

        ksort($value);

        return array_map(fn ($item) => $this->sortCanonicalValue($item), $value);
    }
}
