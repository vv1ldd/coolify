<?php

namespace App\Services;

use App\Models\Sl1PeerNode;
use App\Models\Sl1PeerObservedEvent;
use App\Models\Sl1PeerSyncCursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class Sl1PeerEventSyncService
{
    /**
     * Fetch remote events and store them as candidate evidence only.
     *
     * @return array{ok: bool, peer: Sl1PeerNode, imported: int, cursor: Sl1PeerSyncCursor, error?: string}
     */
    public function fetchIdentityEvents(Sl1PeerNode $peer, int $limit = 100): array
    {
        $cursor = Sl1PeerSyncCursor::query()->firstOrCreate(
            [
                'sl1_peer_node_id' => $peer->id,
                'cursor_type' => Sl1PeerSyncCursor::TYPE_IDENTITY_EVENTS,
            ],
            [
                'remote_cursor' => '0',
                'status' => Sl1PeerSyncCursor::STATUS_IDLE,
            ]
        );

        try {
            if ($peer->status !== Sl1PeerNode::STATUS_VERIFIED) {
                throw new RuntimeException('Peer must be verified before event observation.');
            }

            $payload = $this->fetchRemoteEvents($peer, $cursor->remote_cursor ?: '0', $limit);
            $imported = $this->storeObservedEvents($peer, $payload);

            $cursor->forceFill([
                'remote_cursor' => (string) data_get($payload, 'next_cursor', $cursor->remote_cursor ?: '0'),
                'status' => Sl1PeerSyncCursor::STATUS_SYNCED,
                'last_error' => null,
                'last_synced_at' => now(),
            ])->save();

            return [
                'ok' => true,
                'peer' => $peer,
                'imported' => $imported,
                'cursor' => $cursor->refresh(),
            ];
        } catch (Throwable $e) {
            $cursor->forceFill([
                'status' => Sl1PeerSyncCursor::STATUS_FAILED,
                'last_error' => $e->getMessage(),
            ])->save();

            return [
                'ok' => false,
                'peer' => $peer,
                'imported' => 0,
                'cursor' => $cursor->refresh(),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchRemoteEvents(Sl1PeerNode $peer, string $cursor, int $limit): array
    {
        $response = Http::timeout(10)->acceptJson()->get($peer->issuer.'/events', [
            'after_id' => $cursor,
            'limit' => min(max($limit, 1), 500),
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Peer event stream failed: {$peer->issuer}/events");
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new RuntimeException('Peer event stream did not return JSON.');
        }
        if (data_get($payload, 'protocol') !== 'simple-l1' || data_get($payload, 'stream') !== 'identity_events') {
            throw new RuntimeException('Peer event stream is not an SL1 identity event stream.');
        }
        if (data_get($payload, 'authoritative') !== false) {
            throw new RuntimeException('Peer event stream must be explicitly non-authoritative.');
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeObservedEvents(Sl1PeerNode $peer, array $payload): int
    {
        $events = data_get($payload, 'events', []);
        if (! is_array($events)) {
            throw new RuntimeException('Peer event stream events field is invalid.');
        }

        return DB::transaction(function () use ($peer, $events) {
            $imported = 0;
            foreach ($events as $event) {
                if (! is_array($event)) {
                    continue;
                }

                $hash = (string) data_get($event, 'event_hash', '');
                if ($hash === '') {
                    continue;
                }

                $observed = Sl1PeerObservedEvent::query()->firstOrCreate(
                    [
                        'sl1_peer_node_id' => $peer->id,
                        'remote_event_hash' => $hash,
                    ],
                    [
                        'remote_event_id' => (string) data_get($event, 'id', ''),
                        'remote_event_uuid' => data_get($event, 'uuid'),
                        'event_type' => (string) data_get($event, 'event_type', 'unknown'),
                        'entity_address' => data_get($event, 'entity_address'),
                        'controller_address' => data_get($event, 'controller_address'),
                        'source' => data_get($event, 'source'),
                        'remote_payload' => data_get($event, 'payload', []),
                        'remote_envelope' => $event,
                        'admissibility_status' => Sl1PeerObservedEvent::STATUS_CANDIDATE,
                        'occurred_at' => data_get($event, 'occurred_at'),
                        'observed_at' => now(),
                    ]
                );

                if ($observed->wasRecentlyCreated) {
                    $imported++;
                }
            }

            return $imported;
        });
    }
}
