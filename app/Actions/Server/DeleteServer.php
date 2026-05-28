<?php

namespace App\Actions\Server;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Models\Team;
use App\Notifications\Server\HetznerDeletionFailed;
use App\Services\HetznerService;
use App\Services\InfraLedgerService;
use App\Services\PolicyEngine;
use Lorisleiva\Actions\Concerns\AsAction;

class DeleteServer
{
    use AsAction;

    public function handle(int $serverId, bool $deleteFromHetzner = false, ?int $hetznerServerId = null, ?int $cloudProviderTokenId = null, ?int $teamId = null, bool $mandateApproved = false)
    {
        $server = Server::withTrashed()->find($serverId);

        // 🏛️ Sovereign operational approval interception
        if (! $mandateApproved && $server && app(PolicyEngine::class)->requiresApproval('server.removed')) {
            app(PolicyEngine::class)->stage(
                eventType: 'server.removed',
                entity: $server,
                payload: [
                    'server_id' => $serverId,
                    'from_hetzner' => $deleteFromHetzner,
                    'hetzner_server_id' => $hetznerServerId,
                    'token_id' => $cloudProviderTokenId,
                    'team_id' => $teamId,
                    'server_name' => $server->name,
                ],
                teamId: $teamId ?? $server->team_id
            );

            // Inform the user session
            session()->flash('success', 'Node decommissioning request staged in Pending Intents pool for cryptographic signatures clearance.');

            return;
        }

        // Delete from Hetzner even if server is already gone from Coolify
        if ($deleteFromHetzner && ($hetznerServerId || ($server && $server->hetzner_server_id))) {
            $this->deleteFromHetznerById(
                $hetznerServerId ?? $server->hetzner_server_id,
                $cloudProviderTokenId ?? $server->cloud_provider_token_id,
                $teamId ?? $server->team_id
            );
        }

        ray($server ? 'Deleting server from Coolify' : 'Server already deleted from Coolify, skipping Coolify deletion');

        // If server is already deleted from Coolify, skip this part
        if (! $server) {
            return; // Server already force deleted from Coolify
        }

        ray('force deleting server from Coolify', ['server_id' => $server->id]);

        // ⚓ Sovereign Ledger: server.removed — node exits the topology permanently
        // Capture identity snapshot BEFORE deletion so it's preserved in chain history
        rescue(fn () => app(InfraLedgerService::class)->record(
            eventType: 'server.removed',
            entity: $server,
            payload: [
                'uuid' => $server->uuid,
                'name' => $server->name,
                'ip' => '[REDACTED]',
                'team_id' => $server->team_id,
                'from_hetzner' => $deleteFromHetzner,
            ],
            inputState: [
                'status' => $server->settings?->is_reachable ? 'reachable' : 'unreachable',
                'is_swarm' => (bool) $server->settings?->is_swarm_manager,
            ],
            outputState: ['result' => 'removed'],
            actor: 'DID:SYS|USER:#'.(auth()->id() ?? 'system'),
            teamId: $server->team_id,
        ));

        try {
            $server->forceDelete();
        } catch (\Throwable $e) {
            ray('Failed to force delete server from Coolify', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
            logger()->error('Failed to force delete server from Coolify', [
                'error' => $e->getMessage(),
                'server_id' => $server->id,
            ]);
        }
    }

    private function deleteFromHetznerById(int $hetznerServerId, ?int $cloudProviderTokenId, int $teamId): void
    {
        try {
            // Use the provided token, or fallback to first available team token
            $token = null;

            if ($cloudProviderTokenId) {
                $token = CloudProviderToken::find($cloudProviderTokenId);
            }

            if (! $token) {
                $token = CloudProviderToken::where('team_id', $teamId)
                    ->where('provider', 'hetzner')
                    ->first();
            }

            if (! $token) {
                ray('No Hetzner token found for team, skipping Hetzner deletion', [
                    'team_id' => $teamId,
                    'hetzner_server_id' => $hetznerServerId,
                ]);

                return;
            }

            $hetznerService = new HetznerService($token->token);
            $hetznerService->deleteServer($hetznerServerId);

            ray('Deleted server from Hetzner', [
                'hetzner_server_id' => $hetznerServerId,
                'team_id' => $teamId,
            ]);
        } catch (\Throwable $e) {
            ray('Failed to delete server from Hetzner', [
                'error' => $e->getMessage(),
                'hetzner_server_id' => $hetznerServerId,
                'team_id' => $teamId,
            ]);

            // Log the error but don't prevent the server from being deleted from Coolify
            logger()->error('Failed to delete server from Hetzner', [
                'error' => $e->getMessage(),
                'hetzner_server_id' => $hetznerServerId,
                'team_id' => $teamId,
            ]);

            // Notify the team about the failure
            $team = Team::find($teamId);
            $team?->notify(new HetznerDeletionFailed($hetznerServerId, $teamId, $e->getMessage()));
        }
    }
}
