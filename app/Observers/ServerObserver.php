<?php

namespace App\Observers;

use App\Models\Server;
use App\Services\InfraLedgerService;

/**
 * Server Lifecycle Observer
 *
 * Records topology changes as verifiable state transitions in the Infra Ledger.
 * Each event captures the server's identity at the moment of transition.
 */
class ServerObserver
{
    /**
     * server.provisioned — a new node joins the topology.
     * This is the genesis entry for this server's lineage in the chain.
     */
    public function created(Server $server): void
    {
        rescue(fn () => app(InfraLedgerService::class)->record(
            eventType: 'server.provisioned',
            entity: $server,
            payload: [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'team_id' => $server->team_id,
                'user' => $server->user,
                'port' => $server->port,
            ],
            outputState: ['result' => 'provisioned'],
            teamId: $server->team_id,
        ));
    }
}
