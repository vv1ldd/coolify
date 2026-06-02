<?php

namespace App\Services\EdgeControl\Contracts;

use App\Models\EdgeProjection;

interface HealthObservationAdapter
{
    public function key(): string;

    public function requiredCapabilities(): array;

    public function observe(EdgeProjection $projection): array;
}
