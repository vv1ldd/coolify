<?php

namespace App\Console\Commands;

use App\Models\ControlPlanePeer;
use App\Services\ControlPlane\ControlPlaneSyncService;
use Illuminate\Console\Command;

class ControlPlaneHeartbeatCommand extends Command
{
    protected $signature = 'control-plane:heartbeat
        {--team= : Build the local snapshot for one team ID}
        {--peer=* : Limit configured peer targets by id or uuid}
        {--send : POST the snapshot to configured peer endpoints}
        {--json : Emit the structured result as JSON}';

    protected $description = 'Build a local control-plane heartbeat snapshot and optionally send it to configured peers';

    public function handle(ControlPlaneSyncService $sync): int
    {
        $teamId = filled($this->option('team')) ? (int) $this->option('team') : null;
        $send = (bool) $this->option('send');
        $peerFilters = collect($this->option('peer'))->filter()->values();

        $snapshot = $sync->buildLocalSnapshot($teamId);
        $peers = ControlPlanePeer::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->when($peerFilters->isNotEmpty(), function ($query) use ($peerFilters) {
                $query->where(function ($query) use ($peerFilters) {
                    $query->whereIn('uuid', $peerFilters)
                        ->orWhereIn('id', $peerFilters->filter(fn (string $value): bool => ctype_digit($value))->map(fn (string $value): int => (int) $value));
                });
            })
            ->orderBy('id')
            ->get();

        $result = [
            'dry_run' => ! $send,
            'snapshot' => $snapshot,
            'targets' => $peers->map(fn (ControlPlanePeer $peer): array => $sync->publishSnapshotToPeer($peer, $snapshot, ! $send))->values()->all(),
        ];

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $mode = $send ? 'send' : 'dry-run';
        $this->info("Control-plane heartbeat prepared ({$mode}).");
        $this->line('Observed servers: '.data_get($snapshot, 'servers_summary.total', 0));
        $this->line('Healthy servers: '.data_get($snapshot, 'servers_summary.healthy', 0));
        $this->line('DNS steering policies: '.data_get($snapshot, 'dns_steering_readiness.policies', 0));
        $this->line('Peer targets: '.count($result['targets']));

        foreach ($result['targets'] as $target) {
            $this->line(sprintf(
                ' - %s endpoint=%s ready=%s reason=%s',
                $target['peer_uuid'],
                $target['endpoint_url'] ?: 'n/a',
                $target['ready'] ? 'yes' : 'no',
                $target['reason'] ?: 'sent',
            ));
        }

        return self::SUCCESS;
    }
}
