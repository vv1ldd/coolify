<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\IncidentProtection\ProtectionAction;
use Illuminate\Http\Client\Response;

final class HostingerVpsProviderAdapter extends AbstractProviderServerActionAdapter
{
    public function providerKey(): string
    {
        return 'hostinger_vps';
    }

    public function listServers(?CloudProviderToken $token = null): array
    {
        $response = $this->http($token)->get('/api/vps/v1/virtual-machines');

        return collect($this->jsonOrFail($response))
            ->map(fn (array $server): ProviderServer => $this->serverFromPayload($server))
            ->all();
    }

    public function inspectServer(string|int $providerServerId, ?CloudProviderToken $token = null): ProviderServer
    {
        $response = $this->http($token)->get("/api/vps/v1/virtual-machines/{$providerServerId}");

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
                method: 'POST',
                endpoint: "/api/vps/v1/virtual-machines/{$serverId}/stop",
            ),
            'reboot' => new ProviderActionPlan(
                provider: $this->providerKey(),
                action: $action,
                serverId: $serverId,
                supported: true,
                method: 'POST',
                endpoint: "/api/vps/v1/virtual-machines/{$serverId}/restart",
            ),
            'start' => new ProviderActionPlan(
                provider: $this->providerKey(),
                action: $action,
                serverId: $serverId,
                supported: true,
                dangerous: false,
                requiresApproval: false,
                method: 'POST',
                endpoint: "/api/vps/v1/virtual-machines/{$serverId}/start",
            ),
            'isolate' => $this->isolationFirewallId()
                ? new ProviderActionPlan(
                    provider: $this->providerKey(),
                    action: $action,
                    serverId: $serverId,
                    supported: true,
                    method: 'POST',
                    endpoint: "/api/vps/v1/firewall/{$this->isolationFirewallId()}/activate/{$serverId}",
                    metadata: ['isolation_strategy' => 'activate_preconfigured_firewall'],
                )
                : $this->unsupportedPlan($action, $serverId, 'Hostinger isolation requires a preconfigured firewall ID.'),
            default => $this->unsupportedPlan($action, $serverId, 'Unsupported Hostinger VPS provider action.'),
        };
    }

    public function startServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->postServerAction('start', $providerServerId);
    }

    public function rebootServer(string|int $providerServerId): ProviderActionResult
    {
        return $this->postServerAction('reboot', $providerServerId, 'restart');
    }

    public function powerOffServer(Server $server, ProtectionAction $action): array
    {
        return $this->postServerAction('poweroff', $this->providerServerId($server), 'stop')->toArray();
    }

    public function isolateServer(Server $server, ProtectionAction $action): array
    {
        $serverId = $this->providerServerId($server);
        $firewallId = $this->isolationFirewallId();

        if (! $firewallId) {
            return $this->planAction('isolate', $serverId, $server)->toArray();
        }

        $response = $this->http()->post("/api/vps/v1/firewall/{$firewallId}/activate/{$serverId}");

        return $this->actionResultFromPayload('isolate', $serverId, $this->jsonOrFail($response))->toArray();
    }

    protected function authHeaders(string $token): array
    {
        return ['Authorization' => 'Bearer '.$token];
    }

    private function postServerAction(string $action, string|int $providerServerId, ?string $endpointAction = null): ProviderActionResult
    {
        $serverId = (string) $providerServerId;
        $endpointAction ??= $action;
        $response = $this->http()->post("/api/vps/v1/virtual-machines/{$serverId}/{$endpointAction}");

        return $this->actionResultFromPayload($action, $serverId, $this->jsonOrFail($response));
    }

    private function actionResultFromPayload(string $action, string $serverId, array $payload): ProviderActionResult
    {
        return new ProviderActionResult(
            provider: $this->providerKey(),
            action: $action,
            serverId: $serverId,
            accepted: true,
            status: data_get($payload, 'state'),
            actionId: filled(data_get($payload, 'id')) ? (string) data_get($payload, 'id') : null,
            providerResponse: $this->safeActionResponse($payload),
        );
    }

    private function serverFromPayload(array $payload): ProviderServer
    {
        $ipv4 = $this->ipAddresses((array) data_get($payload, 'ipv4', []));
        $ipv6 = $this->ipAddresses((array) data_get($payload, 'ipv6', []));
        $publicIps = array_values(array_merge($ipv4, $ipv6));

        return new ProviderServer(
            provider: $this->providerKey(),
            id: (string) data_get($payload, 'id'),
            name: data_get($payload, 'hostname'),
            status: data_get($payload, 'state'),
            primaryIp: $ipv4[0] ?? $ipv6[0] ?? null,
            publicIps: $publicIps,
            metadata: [
                'plan' => data_get($payload, 'plan'),
                'firewall_group_id' => data_get($payload, 'firewall_group_id') ?? data_get($payload, 'firewallGroupId'),
                'actions_lock' => data_get($payload, 'actions_lock') ?? data_get($payload, 'actionsLock'),
                'data_center_id' => data_get($payload, 'data_center_id') ?? data_get($payload, 'dataCenterId'),
            ],
        );
    }

    /**
     * @param  array<int, array<string, mixed>|string>  $addresses
     * @return array<int, string>
     */
    private function ipAddresses(array $addresses): array
    {
        return collect($addresses)
            ->map(fn (mixed $address): ?string => is_array($address) ? data_get($address, 'address') : (string) $address)
            ->filter(fn (?string $address): bool => filled($address))
            ->values()
            ->all();
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
            'id' => data_get($payload, 'id'),
            'name' => data_get($payload, 'name'),
            'state' => data_get($payload, 'state'),
        ];
    }

    private function isolationFirewallId(): ?string
    {
        $firewallId = config('sovereign.provider_control.providers.hostinger_vps.isolation_firewall_id');

        return filled($firewallId) ? (string) $firewallId : null;
    }
}
