<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\IncidentProtection\ProtectionAction;

final class NoopProviderServerActionAdapter implements ProviderServerActionAdapter
{
    public function providerKey(): string
    {
        return 'noop';
    }

    public function listServers(?CloudProviderToken $token = null): array
    {
        return [];
    }

    public function inspectServer(string|int $providerServerId, ?CloudProviderToken $token = null): ProviderServer
    {
        return new ProviderServer(
            provider: $this->providerKey(),
            id: (string) $providerServerId,
            metadata: ['provider_call' => 'not_configured'],
        );
    }

    public function planAction(string $action, string|int $providerServerId, ?Server $server = null): ProviderActionPlan
    {
        return new ProviderActionPlan(
            provider: $this->providerKey(),
            action: $action,
            serverId: (string) $providerServerId,
            supported: false,
            reason: 'No provider adapter is configured; no provider API call was made.',
        );
    }

    public function startServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->noopActionResult('start', $providerServerId);
    }

    public function rebootServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->noopActionResult('reboot', $providerServerId);
    }

    public function isolateServer(Server $server, ProtectionAction $action): array
    {
        return $this->dryRunResult($server, $action);
    }

    public function powerOffServer(Server $server, ProtectionAction $action): array
    {
        return $this->dryRunResult($server, $action);
    }

    private function dryRunResult(Server $server, ProtectionAction $action): array
    {
        return [
            'provider_call' => 'not_configured',
            'server_id' => $server->id,
            'action' => $action->type->value,
            'message' => 'No provider adapter is configured; no provider API call was made.',
        ];
    }

    private function noopActionResult(string $action, string|int $providerServerId): ProviderActionResult
    {
        return new ProviderActionResult(
            provider: $this->providerKey(),
            action: $action,
            serverId: (string) $providerServerId,
            accepted: false,
            status: 'not_configured',
            message: 'No provider adapter is configured; no provider API call was made.',
        );
    }
}
