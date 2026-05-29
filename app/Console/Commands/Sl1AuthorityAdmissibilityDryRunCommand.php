<?php

namespace App\Console\Commands;

use App\Models\Sl1PeerObservedEvent;
use App\Services\Sl1AuthorityAdmissibilityService;
use Illuminate\Console\Command;

class Sl1AuthorityAdmissibilityDryRunCommand extends Command
{
    protected $signature = 'sl1:authority-admissibility-dry-run
        {--peer-id= : Evaluate observed events from one peer}
        {--limit=100 : Max observed events to evaluate}
        {--json : Emit a machine-readable semantic report}';

    protected $description = 'Dry-run constitutional admissibility checks for observed SL1 peer evidence';

    public function handle(Sl1AuthorityAdmissibilityService $admissibility): int
    {
        $query = Sl1PeerObservedEvent::query()
            ->with('peer')
            ->whereIn('admissibility_status', [
                Sl1PeerObservedEvent::STATUS_CANDIDATE,
                Sl1PeerObservedEvent::STATUS_DRY_RUN_REJECTED,
                Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE,
            ])
            ->orderBy('id');

        if ($peerId = $this->option('peer-id')) {
            $query->where('sl1_peer_node_id', (int) $peerId);
        }

        $events = $query->limit(max(1, (int) $this->option('limit')))->get();
        if ($events->isEmpty()) {
            if ($this->option('json')) {
                $this->line($this->jsonReport([], false));

                return 0;
            }

            $this->warn('No observed SL1 peer events found for dry-run admissibility.');

            return 0;
        }

        $failed = false;
        $reports = [];
        foreach ($events as $event) {
            $result = $admissibility->dryRun($event);
            $failed = $failed || ! $result['ok'];
            $reports[] = $this->eventReport($event, $result);

            if ($this->option('json')) {
                continue;
            }

            $this->line("event #{$event->id} {$event->remote_event_hash}");
            $this->line("  status={$result['status']}");
            $this->line('  authority_projection=unchanged');
        }

        if ($this->option('json')) {
            $this->line($this->jsonReport($reports, $failed));
        }

        return $failed ? 1 : 0;
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    private function jsonReport(array $events, bool $failed): string
    {
        return json_encode([
            'schema_version' => 'sovereign.runtime.authority_admissibility_dry_run.v1',
            'generated_at' => now()->toISOString(),
            'command' => 'sl1:authority-admissibility-dry-run',
            'authority_projection' => 'unchanged',
            'ok' => ! $failed,
            'summary' => [
                'evaluated' => count($events),
                'admissible' => collect($events)->where('status', Sl1PeerObservedEvent::STATUS_DRY_RUN_ADMISSIBLE)->count(),
                'rejected' => collect($events)->where('status', Sl1PeerObservedEvent::STATUS_DRY_RUN_REJECTED)->count(),
            ],
            'events' => $events,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array{ok: bool, status: string, report: array<string, mixed>}  $result
     * @return array<string, mixed>
     */
    private function eventReport(Sl1PeerObservedEvent $event, array $result): array
    {
        $report = $result['report'];
        $projectionCandidate = $report['projection_candidate'] ?? [];

        return [
            'id' => $event->id,
            'peer' => [
                'id' => $event->peer?->id,
                'issuer' => $event->peer?->issuer,
                'status' => $event->peer?->status,
            ],
            'remote_event_hash' => $event->remote_event_hash,
            'event_type' => $event->event_type,
            'entity_address' => $event->entity_address,
            'controller_address' => $event->controller_address,
            'ok' => $result['ok'],
            'status' => $result['status'],
            'authority_projection' => $report['authority_projection'] ?? 'unchanged',
            'evaluation_contexts' => [
                'checks' => $report['checks'] ?? [],
                'crypto_context' => $report['crypto_context'] ?? [],
                'binding_context' => $report['binding_context'] ?? [],
                'causal_context' => $report['causal_context'] ?? [],
            ],
            'policy_context' => $report['policy_context'] ?? [],
            'projection_candidate' => $projectionCandidate,
            'commit_blockers' => data_get($projectionCandidate, 'commit_blockers', []),
        ];
    }
}
