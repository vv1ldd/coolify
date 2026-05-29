<?php

namespace App\Services;

use App\Models\Sl1NodeIdentity;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class Sl1NodeIdentityService
{
    public const SIGNATURE_SCOPE_IDENTITY_EVENT = 'sl1.peer.identity_event.v1';

    /**
     * @return array<string, mixed>
     */
    public function issuerDocumentIdentity(): array
    {
        $identity = $this->current();

        return [
            'node_id' => $identity->node_id,
            'issuer' => $this->issuerUrl(),
            'signature_algorithm' => $identity->signature_algorithm,
            'public_key' => $identity->public_key,
            'status' => $identity->status,
            'first_seen_at' => $identity->first_seen_at?->toIso8601String(),
            'last_seen_at' => $identity->last_seen_at?->toIso8601String(),
        ];
    }

    public function current(): Sl1NodeIdentity
    {
        $issuer = $this->issuerUrl();
        $identity = Sl1NodeIdentity::query()
            ->where('status', Sl1NodeIdentity::STATUS_ACTIVE)
            ->orderBy('id')
            ->first();

        if ($identity) {
            $identity->forceFill([
                'issuer' => $issuer,
                'last_seen_at' => now(),
            ])->save();

            return $identity;
        }

        if (! function_exists('sodium_crypto_sign_keypair')) {
            throw new RuntimeException('Sodium is required to create an SL1 node identity.');
        }

        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKeyEncoded = sodium_bin2base64($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);

        return Sl1NodeIdentity::create([
            'node_id' => 'sl1node_'.substr(hash('sha256', $publicKeyEncoded), 0, 40),
            'issuer' => $issuer,
            'signature_algorithm' => Sl1NodeIdentity::ALGORITHM_ED25519,
            'public_key' => $publicKeyEncoded,
            'private_key_ciphertext' => Crypt::encryptString(sodium_bin2base64($secretKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)),
            'status' => Sl1NodeIdentity::STATUS_ACTIVE,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function signEventEnvelope(array $event): array
    {
        $identity = $this->current();
        $payload = $this->signaturePayload($event, $identity->node_id, $this->issuerUrl());
        $signature = sodium_crypto_sign_detached(
            $this->canonicalJson($payload),
            $this->decodeSecretKey($identity->private_key_ciphertext)
        );

        $event['node_signature'] = [
            'scope' => self::SIGNATURE_SCOPE_IDENTITY_EVENT,
            'node_id' => $identity->node_id,
            'issuer' => $this->issuerUrl(),
            'algorithm' => $identity->signature_algorithm,
            'signature' => sodium_bin2base64($signature, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
        ];

        return $event;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public function verifyEventEnvelope(array $event, string $nodeId, string $issuer, string $publicKey): bool
    {
        $nodeSignature = data_get($event, 'node_signature');
        if (! is_array($nodeSignature)) {
            return false;
        }
        if (data_get($nodeSignature, 'scope') !== self::SIGNATURE_SCOPE_IDENTITY_EVENT) {
            return false;
        }
        if (data_get($nodeSignature, 'node_id') !== $nodeId) {
            return false;
        }
        if (rtrim((string) data_get($nodeSignature, 'issuer'), '/') !== rtrim($issuer, '/')) {
            return false;
        }
        if (data_get($nodeSignature, 'algorithm') !== Sl1NodeIdentity::ALGORITHM_ED25519) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached(
                sodium_base642bin((string) data_get($nodeSignature, 'signature'), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING),
                $this->canonicalJson($this->signaturePayload($event, $nodeId, $issuer)),
                sodium_base642bin($publicKey, SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING)
            );
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function signaturePayload(array $event, string $nodeId, string $issuer): array
    {
        return [
            'protocol' => 'simple-l1',
            'signature_scope' => self::SIGNATURE_SCOPE_IDENTITY_EVENT,
            'issuer' => rtrim($issuer, '/'),
            'node_id' => $nodeId,
            'event_id' => (string) data_get($event, 'id', ''),
            'event_uuid' => (string) data_get($event, 'uuid', ''),
            'event_type' => (string) data_get($event, 'event_type', ''),
            'event_hash' => (string) data_get($event, 'event_hash', ''),
        ];
    }

    private function issuerUrl(): string
    {
        $path = '/'.trim((string) config('sovereign.sl1_connect.embedded.issuer_path', '/sl1'), '/');
        $base = rtrim((string) config('app.url'), '/');

        return $base.$path;
    }

    private function decodeSecretKey(string $ciphertext): string
    {
        return sodium_base642bin(Crypt::decryptString($ciphertext), SODIUM_BASE64_VARIANT_URLSAFE_NO_PADDING);
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
