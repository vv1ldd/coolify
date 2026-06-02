<?php

namespace App\Services\EdgeControl\Adapters;

use App\Models\EdgeProjection;
use App\Services\EdgeControl\Contracts\HealthObservationAdapter;
use App\Services\EdgeControl\EdgeProjectionService;

class ProbeHealthObservationAdapter implements HealthObservationAdapter
{
    public function __construct(
        private readonly EdgeProjectionService $projections,
    ) {}

    public function key(): string
    {
        return 'probe_health';
    }

    public function requiredCapabilities(): array
    {
        return ['health_observer'];
    }

    public function observe(EdgeProjection $projection): array
    {
        $observation = [
            'projection_uuid' => $projection->uuid,
            'domain' => $projection->domain,
            'adapter' => $projection->adapter,
            'observed_at' => now()->toIso8601String(),
            'status' => 'not_probed',
        ];

        $projection->update([
            'observed_at' => now(),
            'observed_state_hash' => $this->projections->hashPayload($observation),
            'observation_confidence' => 0,
            'observation_quorum_status' => EdgeProjection::QUORUM_PENDING,
            'observation_window_started_at' => now(),
            'observation_window_ended_at' => now(),
        ]);

        return $observation;
    }
}
