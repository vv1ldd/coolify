<?php

namespace App\Console\Commands;

use App\Models\ControlPlanePeer;
use App\Services\ControlPlane\ControlPlaneSyncService;
use Illuminate\Console\Command;

class ControlPlaneStatusCommand extends Command
{
    protected $signature = 'control-plane:status
        {--team= : Limit peers to one team ID}
        {--json : Emit the structured result as JSON}';

    protected $description = 'Show control-plane peer heartbeat status';

    public function handle(ControlPlaneSyncService $sync): int
    {
        $teamId = filled($this->option('team')) ? (int) $this->option('team') : null;
        $peers = ControlPlanePeer::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->withCount('snapshots')
            ->orderBy('team_id')
            ->orderBy('name')
            ->get()
            ->map(fn (ControlPlanePeer $peer): array => $sync->publicPeerStatus($peer))
            ->values()
            ->all();

        if ($this->option('json')) {
            $this->line(json_encode(['peers' => $peers], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if ($peers === []) {
            $this->warn('No control-plane peers configured.');

            return self::SUCCESS;
        }

        $this->table(
            ['UUID', 'Name', 'Region', 'Role', 'Status', 'Last seen', 'Snapshots'],
            collect($peers)->map(fn (array $peer): array => [
                $peer['uuid'],
                $peer['name'] ?: 'n/a',
                $peer['region'] ?: 'n/a',
                $peer['role'] ?: 'n/a',
                $peer['effective_status'],
                $peer['last_seen_at'] ?: 'never',
                $peer['snapshots_count'] ?? 0,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
