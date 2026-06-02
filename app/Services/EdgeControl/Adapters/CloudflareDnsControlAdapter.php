<?php

namespace App\Services\EdgeControl\Adapters;

use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;
use App\Services\EdgeControl\Contracts\DnsControlAdapter;
use App\Services\EdgeControl\EdgeProjectionService;

class CloudflareDnsControlAdapter implements DnsControlAdapter
{
    public function __construct(
        private readonly EdgeProjectionService $projections,
    ) {}

    public function key(): string
    {
        return EdgeProjection::ADAPTER_CLOUDFLARE;
    }

    public function requiredCapabilities(): array
    {
        return [];
    }

    public function apply(EdgeProjection $projection): ?EdgeControlAction
    {
        return $this->projections->recordControlAction(
            projection: $projection,
            actionType: EdgeControlAction::TYPE_DNS_RECORD_UPSERT,
            request: [
                'projection_uuid' => $projection->uuid,
                'payload' => $projection->payload,
            ],
            outcome: [
                'adapter' => $this->key(),
                'status' => 'projection_recorded',
            ],
        );
    }
}
