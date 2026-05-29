<?php

namespace App\Console\Commands;

use App\Models\Sl1PeerObservedEvent;
use App\Services\Sl1AuthorityAdmissibilityService;
use Illuminate\Console\Command;

class Sl1AuthorityAdmissibilityDryRunCommand extends Command
{
    protected $signature = 'sl1:authority-admissibility-dry-run
        {--peer-id= : Evaluate observed events from one peer}
        {--limit=100 : Max observed events to evaluate}';

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
            $this->warn('No observed SL1 peer events found for dry-run admissibility.');

            return 0;
        }

        $failed = false;
        foreach ($events as $event) {
            $result = $admissibility->dryRun($event);
            $failed = $failed || ! $result['ok'];

            $this->line("event #{$event->id} {$event->remote_event_hash}");
            $this->line("  status={$result['status']}");
            $this->line('  authority_projection=unchanged');
        }

        return $failed ? 1 : 0;
    }
}
