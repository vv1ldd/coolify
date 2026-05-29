<?php

namespace App\Console\Commands;

use App\Models\Sl1PeerNode;
use App\Services\Sl1PeerEventSyncService;
use Illuminate\Console\Command;

class Sl1PeerSyncCommand extends Command
{
    protected $signature = 'sl1:peer-sync
        {--peer-id= : Fetch events from one verified peer}
        {--limit=100 : Max remote events per peer}';

    protected $description = 'Fetch peer SL1 events as read-only candidate evidence';

    public function handle(Sl1PeerEventSyncService $sync): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $peerId = $this->option('peer-id');
        $peers = $peerId
            ? collect([Sl1PeerNode::findOrFail((int) $peerId)])
            : Sl1PeerNode::query()->where('status', Sl1PeerNode::STATUS_VERIFIED)->orderBy('id')->get();

        if ($peers->isEmpty()) {
            $this->warn('No verified SL1 peers found.');

            return 0;
        }

        $failed = false;
        foreach ($peers as $peer) {
            $result = $sync->fetchIdentityEvents($peer, $limit);
            if (! $result['ok']) {
                $failed = true;
                $this->error("failed #{$peer->id} {$peer->issuer}");
                $this->line("  error={$result['error']}");

                continue;
            }

            $this->info("observed #{$peer->id} {$peer->issuer}");
            $this->line("  imported={$result['imported']} cursor={$result['cursor']->remote_cursor}");
            $this->line('  authority_projection=unchanged');
        }

        return $failed ? 1 : 0;
    }
}
