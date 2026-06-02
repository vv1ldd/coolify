<?php

namespace App\Services\IncidentProtection;

use App\Enums\ProtectionLevel;
use Illuminate\Support\Str;

final class ProtectionActionPlan
{
    public readonly string $planId;

    /**
     * @param  array<int, string>  $signals
     * @param  array<string, mixed>  $context
     * @param  array<int, ProtectionAction>  $actions
     */
    public function __construct(
        public readonly int $teamId,
        public readonly ProtectionLevel $level,
        public readonly int $severity,
        public readonly array $signals,
        public readonly array $context,
        public readonly array $actions,
        public readonly ?string $runbook = null,
        ?string $planId = null,
    ) {
        $this->planId = $planId ?: (string) Str::uuid();
    }

    public function id(): string
    {
        return $this->planId;
    }

    public function toArray(): array
    {
        return [
            'plan_id' => $this->id(),
            'team_id' => $this->teamId,
            'level' => $this->level->value,
            'severity' => $this->severity,
            'signals' => $this->signals,
            'context' => $this->context,
            'runbook' => $this->runbook,
            'actions' => array_map(
                fn (ProtectionAction $action): array => $action->toArray(),
                $this->actions,
            ),
        ];
    }
}
