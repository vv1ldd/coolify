<?php

namespace App\Services\IncidentProtection;

use App\Enums\ProtectionLevel;

final class ProtectionRunbook
{
    public function name(): string
    {
        return (string) config('sovereign.incident_protection.runbook', 'sovereign-protection-v1');
    }

    /**
     * @return array<string, mixed>
     */
    public function forLevel(ProtectionLevel $level): array
    {
        return (array) data_get(
            config('sovereign.incident_protection.levels', []),
            $level->value,
            [],
        );
    }

    /**
     * @return array<int, string>
     */
    public function actionsForLevel(ProtectionLevel $level): array
    {
        return array_values((array) data_get($this->forLevel($level), 'actions', []));
    }
}
