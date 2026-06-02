<?php

namespace App\Services\EdgeControl\Contracts;

use App\Models\EdgeControlAction;
use App\Models\EdgeProjection;

interface EdgeRuntimeAdapter
{
    public function key(): string;

    public function requiredCapabilities(): array;

    public function apply(EdgeProjection $projection): ?EdgeControlAction;
}
