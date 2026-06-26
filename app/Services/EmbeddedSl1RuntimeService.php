<?php

namespace App\Services;

use App\Models\Sl1Controller;
use App\Models\Sl1Entity;
use App\Models\Sl1IdentityEvent;
use Illuminate\Support\Facades\DB;

class EmbeddedSl1RuntimeService
{
    public const VERSION = 'coolify.embedded-sl1.runtime.v0';

    /**
     * Mirror a verified external SL1 proof into Coolify's durable authority store.
     *
     * @param  array{identity: array<string, mixed>, proof: array<string, mixed>}  $verified
     */
    public function recordVerifiedIdentity(array $verified, string $source): ?Sl1IdentityEvent
    {
        if (! config('sovereign.sl1_connect.embedded.enabled', true)) {
            return null;
        }

        $proof = $verified['proof'] ?? [];
        $identity = $verified['identity'] ?? [];
        $entityAddress = (string) (data_get($proof, 'entity_l1_address') ?: data_get($identity, 'entity_l1_address'));
        if ($entityAddress === '') {
            return null;
        }

        return DB::transaction(function () use ($proof, $identity, $entityAddress, $source) {
            $controllerAddress = (string) (data_get($proof, 'controller_l1_address') ?: data_get($proof, 'keyAddress') ?: data_get($identity, 'key_l1_address'));
            $credentialId = data_get($proof, 'credential_id') ?: data_get($identity, 'credential_id') ?: data_get($identity, 'credentialId');
            $credentialPublicKey = data_get($proof, 'credential_public_key') ?: data_get($identity, 'credential_public_key') ?: data_get($identity, 'credentialPublicKey');
            $proofId = data_get($proof, 'proof_id') ?: data_get($proof, 'proofId');

            $entity = Sl1Entity::query()->firstOrNew(['entity_address' => $entityAddress]);
            $previousEventHash = $entity->current_event_hash;
            $entity->forceFill([
                'alias' => data_get($proof, 'alias') ?: data_get($identity, 'alias') ?: $entity->alias,
                'display_alias' => data_get($proof, 'display_alias') ?: data_get($identity, 'display_alias') ?: data_get($proof, 'displayName') ?: $entity->display_alias,
                'status' => 'active',
                'metadata' => array_filter([
                    'last_source' => $source,
                    'last_proof_type' => data_get($proof, 'type') ?: data_get($proof, 'object_type'),
                    'last_proof_id' => $proofId,
                ]),
                'last_verified_at' => now(),
            ])->save();

            $payload = [
                'version' => self::VERSION,
                'source' => $source,
                'identity' => $identity,
                'proof' => $proof,
            ];
            $eventHash = hash('sha256', $this->canonicalJson([
                'event_type' => 'sl1.identity.proof.observed',
                'entity_address' => $entityAddress,
                'controller_address' => $controllerAddress ?: null,
                'proof_id' => $proofId,
                'payload' => $payload,
            ]));

            $event = Sl1IdentityEvent::query()->firstOrCreate(
                ['event_hash' => $eventHash],
                [
                    'event_type' => 'sl1.identity.proof.observed',
                    'entity_address' => $entityAddress,
                    'controller_address' => $controllerAddress ?: null,
                    'proof_id' => $proofId,
                    'source' => $source,
                    'previous_event_hash' => $previousEventHash,
                    'payload' => $payload,
                    'occurred_at' => now(),
                ]
            );

            $entity->forceFill([
                'current_event_hash' => $event->event_hash,
            ])->save();

            if ($controllerAddress !== '' || $credentialId || $credentialPublicKey) {
                $credentialHash = $credentialId ? hash('sha256', (string) $credentialId) : null;
                Sl1Controller::query()->updateOrCreate(
                    [
                        'entity_address' => $entityAddress,
                        'controller_address' => $controllerAddress ?: null,
                    ],
                    [
                        'credential_hash' => $credentialHash,
                        'credential_id' => $credentialId,
                        'credential_public_key' => $credentialPublicKey,
                        'transports' => data_get($identity, 'transports') ?: data_get($proof, 'transports'),
                        'rp_id' => data_get($identity, 'rp_id') ?: data_get($proof, 'rp_id'),
                        'status' => 'active',
                        'added_at' => now(),
                        'last_used_at' => now(),
                        'meta' => array_filter([
                            'source' => $source,
                            'proof_id' => $proofId,
                        ]),
                    ]
                );
            }

            return $event;
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'protocol' => 'simple-l1',
            'runtime' => self::VERSION,
            'runtime_version' => self::VERSION,
            'protocol_version' => (string) config('sovereign.sl1_connect.embedded.identity_protocol_version', 'capsule-v0'),
            'mode' => 'embedded',
            'enabled' => (bool) config('sovereign.sl1_connect.embedded.enabled', true),
            'issuer' => $this->issuerUrl(),
            'storage' => 'coolify-postgres',
            'storage_role' => (string) config('sovereign.sl1_connect.embedded.storage_role', 'cache'),
            'identity_authority' => 'identity_capsule+state_proof+webauthn_assertion',
            'identity_capsules_enabled' => (bool) config('sovereign.sl1_connect.embedded.identity_capsules_enabled', true),
            'assurance_levels' => [
                'AL0' => 'provenance_only',
                'AL1' => 'provenance_plus_possession',
                'AL2' => 'provenance_plus_possession_plus_bounded_state_proof',
                'AL3' => 'provenance_plus_possession_plus_current_quorum_state',
            ],
            'default_assurance_level' => (string) config('sovereign.sl1_connect.embedded.default_assurance_level', 'AL1'),
            'resolvers' => [
                'evidence' => config('sovereign.sl1_connect.embedded.evidence_resolvers', []),
                'state' => config('sovereign.sl1_connect.embedded.state_resolvers', []),
            ],
            'entities' => Sl1Entity::query()->count(),
            'controllers' => Sl1Controller::query()->count(),
            'events' => Sl1IdentityEvent::query()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function eventStream(int $afterId = 0, int $limit = 100, ?Sl1NodeIdentityService $nodeIdentity = null): array
    {
        $limit = min(max($limit, 1), 500);
        $events = Sl1IdentityEvent::query()
            ->where('id', '>', max(0, $afterId))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $nextCursor = (string) ($events->last()?->id ?? $afterId);

        return [
            'protocol' => 'simple-l1',
            'runtime' => self::VERSION,
            'mode' => 'embedded',
            'issuer' => $this->issuerUrl(),
            'stream' => 'identity_events',
            'cursor' => (string) max(0, $afterId),
            'next_cursor' => $nextCursor,
            'authoritative' => false,
            'events' => $events->map(function (Sl1IdentityEvent $event) use ($nodeIdentity) {
                $envelope = [
                    'id' => (string) $event->id,
                    'uuid' => $event->uuid,
                    'event_type' => $event->event_type,
                    'entity_address' => $event->entity_address,
                    'controller_address' => $event->controller_address,
                    'proof_id' => $event->proof_id,
                    'source' => $event->source,
                    'event_hash' => $event->event_hash,
                    'previous_event_hash' => $event->previous_event_hash,
                    'payload' => $event->payload ?? [],
                    'occurred_at' => $event->occurred_at?->toIso8601String(),
                    'created_at' => $event->created_at?->toIso8601String(),
                ];

                return $nodeIdentity?->signEventEnvelope($envelope) ?? $envelope;
            })->values()->all(),
        ];
    }

    public function issuerUrl(): string
    {
        $configuredIssuer = config('sovereign.sl1_connect.embedded.issuer_url');
        if (filled($configuredIssuer)) {
            return rtrim((string) $configuredIssuer, '/');
        }

        $path = '/'.trim((string) config('sovereign.sl1_connect.embedded.issuer_path', '/sl1'), '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base.$path;
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->sortCanonicalValue($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
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
