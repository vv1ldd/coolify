<?php

namespace App\Console\Commands;

use App\Models\Sl1PeerNode;
use App\Services\Sl1PeerRegistryService;
use Illuminate\Console\Command;

class Sl1PeerVerifyCommand extends Command
{
    protected $signature = 'sl1:peer-verify
        {--peer-id= : Verify one peer by database id}';

    protected $description = 'Verify registered sovereign SL1 peers without syncing authority events';

    public function handle(Sl1PeerRegistryService $peers): int
    {
        $peerId = $this->option('peer-id');
        $results = $peerId
            ? [$peers->verify(Sl1PeerNode::findOrFail((int) $peerId))]
            : $peers->verifyAll();

        if ($results === []) {
            $this->warn('No SL1 peers registered.');

            return 0;
        }

        $failed = false;
        foreach ($results as $result) {
            $peer = $result['peer'];
            if ($result['ok']) {
                $this->info("verified #{$peer->id} {$peer->issuer}");
                $this->line("  runtime={$peer->runtime} storage={$peer->storage}");
                $this->line('  capabilities='.implode(',', $peer->capabilities ?? []));
            } else {
                $failed = true;
                $this->error("failed #{$peer->id} {$peer->issuer}");
                $this->line("  error={$result['error']}");
            }
        }

        return $failed ? 1 : 0;
    }
}
