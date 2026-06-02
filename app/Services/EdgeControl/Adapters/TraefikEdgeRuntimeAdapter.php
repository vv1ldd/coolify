<?php

namespace App\Services\EdgeControl\Adapters;

use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;
use App\Services\EdgeControl\Contracts\EdgeRuntimeAdapter;
use App\Services\EdgeControl\EdgeProjectionService;

class TraefikEdgeRuntimeAdapter implements EdgeRuntimeAdapter
{
    public function __construct(
        private readonly EdgeProjectionService $projections,
    ) {}

    public function key(): string
    {
        return EdgeProjection::ADAPTER_TRAEFIK;
    }

    public function requiredCapabilities(): array
    {
        return ['edge_runtime'];
    }

    public function apply(EdgeProjection $projection): ?EdgeControlAction
    {
        return $this->projections->recordControlAction(
            projection: $projection,
            actionType: EdgeControlAction::TYPE_EDGE_RUNTIME_APPLY,
            request: [
                'projection_uuid' => $projection->uuid,
                'payload' => $projection->payload,
            ],
            outcome: [
                'adapter' => $this->key(),
                'status' => 'projection_recorded',
                'runtime' => 'traefik',
            ],
        );
    }
}
