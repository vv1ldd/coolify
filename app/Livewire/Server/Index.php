<?php

namespace App\Livewire\Server;

use App\Models\Server;
use App\Models\Sl1NodeIdentity;
use App\Models\Sl1PeerNode;
use App\Models\Sl1PeerObservedEvent;
use App\Services\Sl1AuthorityPolicyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $servers = null;

    public array $sl1Network = [];

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->sl1Network = $this->loadSl1Network();
    }

    public function render()
    {
        return view('livewire.server.index');
    }

    private function loadSl1Network(): array
    {
        if (! Schema::hasTable('sl1_peer_nodes') || ! Schema::hasTable('sl1_peer_observed_events')) {
            return [
                'available' => false,
                'reason' => 'SL1 federation tables are not migrated yet.',
            ];
        }

        $localIdentity = Schema::hasTable('sl1_node_identities')
            ? Sl1NodeIdentity::query()->where('status', Sl1NodeIdentity::STATUS_ACTIVE)->latest('id')->first()
            : null;
        $peers = Sl1PeerNode::query()
            ->with(['identities', 'syncCursors'])
            ->withCount('observedEvents')
            ->orderByRaw("status = 'verified' desc")
            ->orderBy('issuer')
            ->get();

        return [
            'available' => true,
            'policy_mode' => Sl1AuthorityPolicyService::MODE_OBSERVE_ONLY,
            'projection_allowed' => false,
            'local' => [
                'node_id' => $localIdentity?->node_id,
                'issuer' => $localIdentity?->issuer ?: rtrim((string) config('app.url'), '/').'/sl1',
                'algorithm' => $localIdentity?->signature_algorithm ?: 'ed25519',
                'status' => $localIdentity?->status ?: 'not_initialized',
            ],
            'summary' => [
                'peers' => $peers->count(),
                'verified' => $peers->where('status', Sl1PeerNode::STATUS_VERIFIED)->count(),
                'observed_events' => Sl1PeerObservedEvent::query()->count(),
                'candidates' => Sl1PeerObservedEvent::query()->where('admissibility_status', Sl1PeerObservedEvent::STATUS_CANDIDATE)->count(),
                'dry_run_admissible' => Sl1PeerObservedEvent::query()->where('admissibility_status', Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE)->count(),
                'dry_run_rejected' => Sl1PeerObservedEvent::query()->where('admissibility_status', Sl1PeerObservedEvent::STATUS_DRY_RUN_REJECTED)->count(),
            ],
            'peers' => $peers->map(fn (Sl1PeerNode $peer) => $this->peerSummary($peer))->values()->all(),
        ];
    }

    private function peerSummary(Sl1PeerNode $peer): array
    {
        $identity = $peer->identities->sortByDesc('last_seen_at')->first();
        $cursor = $peer->syncCursors->firstWhere('cursor_type', 'identity_events');

        return [
            'id' => $peer->id,
            'name' => $peer->name ?: $this->displayIssuer($peer->issuer),
            'issuer' => $peer->issuer,
            'display_issuer' => $this->displayIssuer($peer->issuer),
            'status' => $peer->status,
            'runtime' => $peer->runtime,
            'node_id' => $identity?->peer_node_id,
            'trust_state' => $identity?->trust_state ?: 'unknown',
            'observed_events' => $peer->observed_events_count,
            'cursor' => $cursor?->remote_cursor ?: '0',
            'last_verified_at' => $peer->last_verified_at?->diffForHumans(),
            'last_synced_at' => $cursor?->last_synced_at?->diffForHumans(),
        ];
    }

    private function displayIssuer(string $issuer): string
    {
        $host = parse_url($issuer, PHP_URL_HOST);

        return $host ?: $issuer;
    }
}
