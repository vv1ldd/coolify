<?php

namespace App\Livewire\Server;

use App\Models\DnsRecord;
use App\Models\Server;
use App\Models\SimpleL1ControlAction;
use App\Models\SimpleL1FailoverDecision;
use App\Models\SimpleL1NodeObservation;
use App\Models\Sl1NodeIdentity;
use App\Models\Sl1PeerNode;
use App\Models\Sl1PeerObservedEvent;
use App\Services\Sl1AuthorityPolicyService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Throwable;

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
        $discoveryCandidates = $this->loadDiscoveryCandidates($peers);

        return [
            'available' => true,
            'truth_model' => [
                'discovery_owner' => 'bridge',
                'admission_owner' => 'host',
                'visibility_owner' => 'ui_projection',
            ],
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
                'discovery_candidates' => count($discoveryCandidates),
            ],
            'peers' => $peers->map(fn (Sl1PeerNode $peer) => $this->peerSummary($peer))->values()->all(),
            'discovery_candidates' => $discoveryCandidates,
            'failover' => $this->loadSimpleL1FailoverTimeline(),
        ];
    }

    private function loadSimpleL1FailoverTimeline(): array
    {
        if (
            ! Schema::hasTable('simple_l1_node_observations')
            || ! Schema::hasTable('simple_l1_failover_decisions')
            || ! Schema::hasTable('simple_l1_evidence_packages')
            || ! Schema::hasColumn('simple_l1_failover_decisions', 'simple_l1_evidence_package_id')
        ) {
            return [
                'available' => false,
                'reason' => 'Simple L1 failover observability tables are not migrated yet.',
            ];
        }

        $teamId = currentTeam()?->id;
        if (! $teamId) {
            return [
                'available' => false,
                'reason' => 'No current team is selected.',
            ];
        }

        $latestDecision = SimpleL1FailoverDecision::query()
            ->with('evidencePackage')
            ->where('team_id', $teamId)
            ->latest('decided_at')
            ->first();
        $domain = $latestDecision?->domain ?: (string) env('SIMPLE_L1_DOMAIN', 'simplel1.online');
        $currentRecord = DnsRecord::query()
            ->where('type', 'A')
            ->where('name', $domain)
            ->whereHas('zone', fn ($query) => $query->where('team_id', $teamId))
            ->first();
        $observations = SimpleL1NodeObservation::query()
            ->where('team_id', $teamId)
            ->where('domain', $domain)
            ->latest('observed_at')
            ->limit(20)
            ->get();
        $decisions = SimpleL1FailoverDecision::query()
            ->with('evidencePackage')
            ->where('team_id', $teamId)
            ->where('domain', $domain)
            ->latest('decided_at')
            ->limit(5)
            ->get();
        $controlActions = Schema::hasTable('simple_l1_control_actions')
            ? SimpleL1ControlAction::query()
                ->where('team_id', $teamId)
                ->where('domain', $domain)
                ->latest('executed_at')
                ->limit(5)
                ->get()
            : collect();

        return [
            'available' => true,
            'domain' => $domain,
            'current_target' => $currentRecord?->content,
            'latest_decision' => $latestDecision ? $this->decisionSummary($latestDecision) : null,
            'node_statuses' => $observations
                ->unique('target_ip')
                ->take(6)
                ->map(fn (SimpleL1NodeObservation $observation): array => $this->observationSummary($observation))
                ->values()
                ->all(),
            'timeline' => $this->failoverTimeline($observations, $decisions, $controlActions),
        ];
    }

    private function loadDiscoveryCandidates(Collection $admittedPeers): array
    {
        $bridge = rtrim((string) config('sovereign.sl1_connect.issuer', 'https://simplel1.online'), '/');
        $networkId = (string) config('sovereign.network_id', 'simplel1');
        $admittedIssuers = $admittedPeers
            ->map(fn (Sl1PeerNode $peer) => rtrim($peer->issuer, '/'))
            ->filter()
            ->values();

        try {
            $response = Http::timeout(5)
                ->acceptJson()
                ->get("{$bridge}/api/sl1/network/join-requests", [
                    'network_id' => $networkId,
                ]);

            if ($response->failed()) {
                return [];
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                return [];
            }

            $artifacts = collect(data_get($payload, 'namespace_artifacts', []))
                ->groupBy('request_id');

            return collect(data_get($payload, 'join_requests', []))
                ->filter(fn ($request) => is_array($request))
                ->map(function (array $request) use ($artifacts, $admittedIssuers) {
                    $issuer = rtrim((string) data_get($request, 'issuer_url'), '/');
                    $requestedFqdn = (string) data_get($request, 'requested_fqdn');
                    if ($issuer === '' && $requestedFqdn !== '') {
                        $issuer = "https://{$requestedFqdn}/sl1";
                    }

                    $requestArtifacts = $artifacts->get(data_get($request, 'request_id'), collect());
                    $verificationState = $requestArtifacts->contains('artifact_type', 'issuer_reachable')
                        ? 'verified_candidate'
                        : 'join_request';

                    return [
                        'request_id' => data_get($request, 'request_id'),
                        'host_domain' => data_get($request, 'host_domain') ?: $requestedFqdn,
                        'issuer' => $issuer,
                        'status' => data_get($request, 'status', 'observed'),
                        'verification_state' => $verificationState,
                        'artifact_count' => $requestArtifacts->count(),
                        'already_admitted' => $issuer !== '' && $admittedIssuers->contains($issuer),
                    ];
                })
                ->reject(fn (array $request) => $request['already_admitted'])
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
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

    private function observationSummary(SimpleL1NodeObservation $observation): array
    {
        return [
            'target_node' => $observation->target_node,
            'target_ip' => $observation->target_ip,
            'status' => $observation->status,
            'health_source' => $observation->health_source,
            'health_url' => $observation->health_url,
            'observed_at' => $observation->observed_at?->diffForHumans(),
            'sort_at' => $observation->observed_at?->getTimestamp() ?? 0,
        ];
    }

    private function decisionSummary(SimpleL1FailoverDecision $decision): array
    {
        return [
            'recommendation' => $decision->recommendation,
            'reason' => $decision->reason,
            'previous_target' => $decision->previous_target,
            'new_target' => $decision->new_target,
            'evidence_hash' => $decision->evidence_hash,
            'evidence_short' => substr($decision->evidence_hash, 0, 12),
            'evidence_package_uuid' => $decision->evidencePackage?->uuid,
            'applied_at' => $decision->applied_at?->diffForHumans(),
            'decided_at' => $decision->decided_at?->diffForHumans(),
            'sort_at' => $decision->decided_at?->getTimestamp() ?? 0,
        ];
    }

    private function failoverTimeline(Collection|\Illuminate\Support\Collection $observations, Collection|\Illuminate\Support\Collection $decisions, Collection|\Illuminate\Support\Collection $controlActions): array
    {
        $observationEvents = $observations->take(8)->map(function (SimpleL1NodeObservation $observation): array {
            return [
                'type' => 'observation',
                'title' => trim(($observation->target_node ?: 'node').' '.$observation->status),
                'detail' => ($observation->target_ip ?: 'unknown ip').' via '.($observation->health_source ?: 'unknown source'),
                'status' => $observation->status,
                'at' => $observation->observed_at?->diffForHumans(),
                'sort_at' => $observation->observed_at?->getTimestamp() ?? 0,
            ];
        });

        $decisionEvents = $decisions->map(function (SimpleL1FailoverDecision $decision): array {
            $movement = $decision->new_target
                ? "{$decision->previous_target} -> {$decision->new_target}"
                : ($decision->previous_target ?: 'no target change');

            return [
                'type' => 'decision',
                'title' => 'Decision: '.$decision->recommendation,
                'detail' => $movement.' / '.$decision->reason,
                'status' => $decision->applied_at ? 'applied' : $decision->recommendation,
                'at' => $decision->decided_at?->diffForHumans(),
                'evidence_short' => substr($decision->evidence_hash, 0, 12),
                'evidence_package_uuid' => $decision->evidencePackage?->uuid,
                'sort_at' => $decision->decided_at?->getTimestamp() ?? 0,
            ];
        });

        $controlActionEvents = $controlActions->map(function (SimpleL1ControlAction $action): array {
            return [
                'type' => 'control_action',
                'title' => 'Control Action: '.$action->action_type,
                'detail' => $action->adapter.' / '.$action->status,
                'status' => $action->status,
                'at' => $action->executed_at?->diffForHumans(),
                'sort_at' => $action->executed_at?->getTimestamp() ?? 0,
            ];
        });

        return $observationEvents
            ->merge($decisionEvents)
            ->merge($controlActionEvents)
            ->sortByDesc('sort_at')
            ->take(10)
            ->values()
            ->all();
    }
}
