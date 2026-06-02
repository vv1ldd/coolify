<?php

namespace App\Services\Provider;

final class ProviderActionResult
{
    /**
     * @param  array<string, mixed>  $providerResponse
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $action,
        public readonly string $serverId,
        public readonly bool $accepted,
        public readonly ?string $status = null,
        public readonly ?string $actionId = null,
        public readonly ?string $message = null,
        public readonly array $providerResponse = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'action' => $this->action,
            'server_id' => $this->serverId,
            'accepted' => $this->accepted,
            'status' => $this->status,
            'action_id' => $this->actionId,
            'message' => $this->message,
            'provider_response' => $this->providerResponse,
        ];
    }
}
