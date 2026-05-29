<?php

namespace App\Console\Commands;

use App\Services\Sl1PeerRegistryService;
use Illuminate\Console\Command;

class Sl1PeerRegisterCommand extends Command
{
    protected $signature = 'sl1:peer-register
        {issuer : Peer issuer URL or host, for example https://peer.example.com/sl1}
        {--name= : Human-readable peer name}
        {--verify : Verify immediately after registration}';

    protected $description = 'Register a sovereign SL1 peer without importing authority events';

    public function handle(Sl1PeerRegistryService $peers): int
    {
        $peer = $peers->register((string) $this->argument('issuer'), $this->option('name') ?: null);
        $this->info("Registered SL1 peer #{$peer->id}: {$peer->issuer}");

        if (! $this->option('verify')) {
            $this->line('Run sl1:peer-verify to verify peer status.');

            return 0;
        }

        $result = $peers->verify($peer);
        if (! $result['ok']) {
            $this->error("Peer verification failed: {$result['error']}");

            return 1;
        }

        $peer = $result['peer'];
        $this->info("Peer verified: {$peer->issuer}");
        $this->line("runtime={$peer->runtime}");
        $this->line("storage={$peer->storage}");
        $this->line('capabilities='.implode(',', $peer->capabilities ?? []));
        $this->line('peer_identities='.$peer->identities()->count());

        return 0;
    }
}
