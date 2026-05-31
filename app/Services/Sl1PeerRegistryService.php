<?php

namespace App\Services;

use App\Models\Sl1PeerIdentity;
use App\Models\Sl1PeerNode;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class Sl1PeerRegistryService
{
    public function register(string $issuer, ?string $name = null): Sl1PeerNode
    {
        $this->requireHostAdmissionActor('host');

        $issuer = $this->normalizeIssuer($issuer);

        return Sl1PeerNode::query()->updateOrCreate(
            ['issuer' => $issuer],
            [
                'name' => $name,
                'status' => Sl1PeerNode::STATUS_PENDING,
                'last_error' => null,
            ]
        );
    }

    private function requireHostAdmissionActor(string $actor): void
    {
        if ($actor !== 'host') {
            throw new RuntimeException('ADMISSION_AUTHORITY_VIOLATION: admitted_peer requires host admission actor.');
        }
    }

    /**
     * @return array{ok: bool, peer: Sl1PeerNode, status?: array<string, mixed>, issuer_document?: array<string, mixed>, error?: string}
     */
    public function verify(Sl1PeerNode $peer): array
    {
        try {
            $status = $this->fetchJson($peer->issuer.'/status');
            $issuerDocument = $this->fetchJson($peer->issuer.'/.well-known/issuer');
            $this->assertValidPeer($peer->issuer, $status, $issuerDocument);
            $this->recordPeerIdentity($peer, $issuerDocument);

            $peer->forceFill([
                'status' => Sl1PeerNode::STATUS_VERIFIED,
                'runtime' => (string) data_get($status, 'runtime'),
                'storage' => (string) data_get($status, 'storage'),
                'capabilities' => data_get($issuerDocument, 'capabilities', []),
                'last_status' => $status,
                'last_issuer_document' => $issuerDocument,
                'last_error' => null,
                'last_verified_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'peer' => $peer->refresh(),
                'status' => $status,
                'issuer_document' => $issuerDocument,
            ];
        } catch (Throwable $e) {
            $peer->forceFill([
                'status' => $e instanceof RuntimeException ? Sl1PeerNode::STATUS_INVALID : Sl1PeerNode::STATUS_UNREACHABLE,
                'last_error' => $e->getMessage(),
            ])->save();

            return [
                'ok' => false,
                'peer' => $peer->refresh(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<int, array{ok: bool, peer: Sl1PeerNode, status?: array<string, mixed>, issuer_document?: array<string, mixed>, error?: string}>
     */
    public function verifyAll(): array
    {
        return Sl1PeerNode::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Sl1PeerNode $peer) => $this->verify($peer))
            ->all();
    }

    private function normalizeIssuer(string $issuer): string
    {
        $issuer = trim($issuer);
        if ($issuer === '') {
            throw new RuntimeException('SL1 peer issuer is required.');
        }

        $issuer = rtrim($issuer, '/');
        if (! str_starts_with($issuer, 'https://') && ! str_starts_with($issuer, 'http://')) {
            $issuer = 'https://'.$issuer;
        }
        if (! str_ends_with($issuer, '/sl1')) {
            $issuer .= '/sl1';
        }

        return $issuer;
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchJson(string $url): array
    {
        $response = Http::timeout(10)->acceptJson()->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Peer endpoint failed: {$url}");
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException("Peer endpoint did not return JSON: {$url}");
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $status
     * @param  array<string, mixed>  $issuerDocument
     */
    private function assertValidPeer(string $expectedIssuer, array $status, array $issuerDocument): void
    {
        if (data_get($status, 'protocol') !== 'simple-l1') {
            throw new RuntimeException('Peer status is not simple-l1.');
        }
        if (data_get($status, 'mode') !== 'embedded') {
            throw new RuntimeException('Peer is not an embedded SL1 runtime.');
        }
        if (data_get($status, 'storage') !== 'coolify-postgres') {
            throw new RuntimeException('Peer does not use Coolify-owned storage.');
        }
        if (rtrim((string) data_get($status, 'issuer'), '/') !== $expectedIssuer) {
            throw new RuntimeException('Peer status issuer does not match registered issuer.');
        }
        if (rtrim((string) data_get($issuerDocument, 'issuer'), '/') !== $expectedIssuer) {
            throw new RuntimeException('Peer issuer document does not match registered issuer.');
        }
    }

    /**
     * @param  array<string, mixed>  $issuerDocument
     */
    private function recordPeerIdentity(Sl1PeerNode $peer, array $issuerDocument): void
    {
        $nodeIdentity = data_get($issuerDocument, 'node_identity');
        if (! is_array($nodeIdentity)) {
            return;
        }

        $nodeId = (string) data_get($nodeIdentity, 'node_id');
        $publicKey = (string) data_get($nodeIdentity, 'public_key');
        $algorithm = (string) data_get($nodeIdentity, 'signature_algorithm', 'ed25519');
        if ($nodeId === '' || $publicKey === '') {
            throw new RuntimeException('Peer issuer document has incomplete node identity.');
        }

        $identity = Sl1PeerIdentity::query()->firstOrNew([
            'sl1_peer_node_id' => $peer->id,
            'peer_node_id' => $nodeId,
        ]);

        $identity->forceFill([
            'signature_algorithm' => $algorithm,
            'peer_public_key' => $publicKey,
            'trust_state' => $identity->trust_state ?: Sl1PeerIdentity::TRUST_OBSERVED,
            'metadata' => [
                'issuer' => data_get($nodeIdentity, 'issuer'),
                'status' => data_get($nodeIdentity, 'status'),
            ],
            'first_seen_at' => $identity->first_seen_at ?: now(),
            'last_seen_at' => now(),
        ])->save();
    }
}
