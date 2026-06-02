<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\IncidentProtection\ProtectionAction;

interface ProviderServerActionAdapter
{
    public function providerKey(): string;

    /**
     * @return array<int, ProviderServer>
     */
    public function listServers(?CloudProviderToken $token = null): array;

    public function inspectServer(string|int $providerServerId, ?CloudProviderToken $token = null): ProviderServer;

    public function planAction(string $action, string|int $providerServerId, ?Server $server = null): ProviderActionPlan;

    public function startServer(string|int $providerServerId): ProviderActionResult;

    public function rebootServer(string|int $providerServerId): ProviderActionResult;

    /**
     * Implementations must not call provider APIs unless the caller already
     * verified approval and environment dry-run guards.
     */
    public function isolateServer(Server $server, ProtectionAction $action): array;

    public function powerOffServer(Server $server, ProtectionAction $action): array;
}
