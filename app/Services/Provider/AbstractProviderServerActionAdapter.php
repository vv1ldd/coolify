<?php

namespace App\Services\Provider;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Services\IncidentProtection\ProtectionAction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class AbstractProviderServerActionAdapter implements ProviderServerActionAdapter
{
    public function __construct(
        protected readonly ?CloudProviderToken $cloudProviderToken = null,
    ) {}

    public function isolateServer(Server $server, ProtectionAction $action): array
    {
        return $this->planAction('isolate', $this->providerServerId($server), $server)->toArray();
    }

    protected function tokenValue(?CloudProviderToken $token = null): string
    {
        $value = (string) (($token ?? $this->cloudProviderToken)?->token ?? '');

        if (blank($value)) {
            throw new ProviderApiException($this->providerKey(), 'Provider API token is not configured.');
        }

        return $value;
    }

    protected function providerServerId(Server $server): string
    {
        $metadata = $server->server_metadata ?? [];
        $candidates = [
            data_get($metadata, 'provider_server_id'),
            data_get($metadata, $this->providerKey().'_server_id'),
            data_get($metadata, 'selectel_vds_ctid'),
            data_get($metadata, 'hostinger_vps_id'),
            $server->hetzner_server_id,
        ];

        $providerServerId = collect($candidates)
            ->first(fn (mixed $candidate): bool => filled($candidate));

        if (blank($providerServerId)) {
            throw new ProviderApiException($this->providerKey(), 'Provider server ID is not configured for this server.');
        }

        return (string) $providerServerId;
    }

    protected function baseUrl(): string
    {
        $configured = (string) config("sovereign.provider_control.providers.{$this->providerKey()}.base_url");

        return rtrim($configured, '/');
    }

    protected function timeout(): int
    {
        return (int) config('sovereign.provider_control.timeout', 10);
    }

    protected function connectTimeout(): int
    {
        return (int) config('sovereign.provider_control.connect_timeout', 5);
    }

    protected function http(?CloudProviderToken $token = null): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout())
            ->connectTimeout($this->connectTimeout())
            ->withHeaders($this->authHeaders($this->tokenValue($token)));
    }

    /**
     * @return array<string, string>
     */
    abstract protected function authHeaders(string $token): array;

    protected function unsupportedPlan(string $action, string|int $providerServerId, string $reason): ProviderActionPlan
    {
        return new ProviderActionPlan(
            provider: $this->providerKey(),
            action: $action,
            serverId: (string) $providerServerId,
            supported: false,
            reason: $reason,
        );
    }
}
