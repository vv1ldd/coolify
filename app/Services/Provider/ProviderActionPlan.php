<?php

namespace App\Services\Provider;

final class ProviderActionPlan
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $action,
        public readonly string $serverId,
        public readonly bool $supported,
        public readonly bool $dangerous = true,
        public readonly bool $requiresApproval = true,
        public readonly bool $dryRun = true,
        public readonly ?string $method = null,
        public readonly ?string $endpoint = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
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
            'supported' => $this->supported,
            'dangerous' => $this->dangerous,
            'requires_approval' => $this->requiresApproval,
            'dry_run' => $this->dryRun,
            'method' => $this->method,
            'endpoint' => $this->endpoint,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
        ];
    }
}
