<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\IncidentProtection\ProtectionAction;
use Illuminate\Http\Client\Response;

final class SelectelVdsProviderAdapter extends AbstractProviderServerActionAdapter
{
    public function providerKey(): string
    {
        return 'selectel_vds';
    }

    public function listServers(?CloudProviderToken $token = null): array
    {
        $response = $this->http($token)->get('/scalets');

        return collect($this->jsonOrFail($response))
            ->map(fn (array $server): ProviderServer => $this->serverFromPayload($server))
            ->all();
    }

    public function inspectServer(string|int $providerServerId, ?CloudProviderToken $token = null): ProviderServer
    {
        $response = $this->http($token)->get("/scalets/{$providerServerId}");

        return $this->serverFromPayload($this->jsonOrFail($response));
    }

    public function planAction(string $action, string|int $providerServerId, ?Server $server = null): ProviderActionPlan
    {
        $serverId = (string) $providerServerId;

        return match ($action) {
            'poweroff' => new ProviderActionPlan(
                provider: $this->providerKey(),
                action: $action,
                serverId: $serverId,
                supported: true,
                method: 'PATCH',
                endpoint: "/scalets/{$serverId}/stop",
            ),
            'reboot' => new ProviderActionPlan(
                provider: $this->providerKey(),
                action: $action,
                serverId: $serverId,
                supported: true,
                method: 'PATCH',
                endpoint: "/scalets/{$serverId}/restart",
            ),
            'start' => new ProviderActionPlan(
                provider: $this->providerKey(),
                action: $action,
                serverId: $serverId,
                supported: true,
                dangerous: false,
                requiresApproval: false,
                method: 'PATCH',
                endpoint: "/scalets/{$serverId}/start",
            ),
            default => $this->unsupportedPlan($action, $serverId, 'Selectel VDS API v1 docs do not expose a firewall isolation endpoint.'),
        };
    }

    public function startServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->patchServerAction('start', $providerServerId);
    }

    public function rebootServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->patchServerAction('reboot', $providerServerId, 'restart');
    }

    public function powerOffServer(Server $server, ProtectionAction $action): array
    {
        return $this->patchServerAction('poweroff', $this->providerServerId($server), 'stop')->toArray();
    }

    protected function authHeaders(string $token): array
    {
        return ['X-Token' => $token];
    }

    private function patchServerAction(string $action, string|int $providerServerId, ?string $endpointAction = null): ProviderActionResult
    {
        $serverId = (string) $providerServerId;
        $endpointAction ??= $action;
        $response = $this->http()->patch("/scalets/{$serverId}/{$endpointAction}", [
            'id' => is_numeric($serverId) ? (int) $serverId : $serverId,
        ]);
        $payload = $this->jsonOrFail($response);

        return new ProviderActionResult(
            provider: $this->providerKey(),
            action: $action,
            serverId: $serverId,
            accepted: true,
            status: (string) data_get($payload, 'status'),
            providerResponse: $this->safeActionResponse($payload),
        );
    }

    private function serverFromPayload(array $payload): ProviderServer
    {
        $publicIp = data_get($payload, 'public_address.address');
        $privateIp = data_get($payload, 'private_address.address');

        return new ProviderServer(
            provider: $this->providerKey(),
            id: (string) data_get($payload, 'ctid'),
            name: data_get($payload, 'name') ?: data_get($payload, 'hostname'),
            status: data_get($payload, 'status'),
            primaryIp: $publicIp,
            publicIps: filled($publicIp) ? [(string) $publicIp] : [],
            privateIps: filled($privateIp) ? [(string) $privateIp] : [],
            metadata: [
                'hostname' => data_get($payload, 'hostname'),
                'location' => data_get($payload, 'location'),
                'plan' => data_get($payload, 'rplan'),
                'active' => data_get($payload, 'active'),
                'locked' => data_get($payload, 'locked'),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOrFail(Response $response): array
    {
        if (! $response->successful()) {
            throw ProviderApiException::requestFailed($this->providerKey(), $response->status());
        }

        return $response->json() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function safeActionResponse(array $payload): array
    {
        return [
            'ctid' => data_get($payload, 'ctid'),
            'status' => data_get($payload, 'status'),
            'name' => data_get($payload, 'name'),
        ];
    }
}
