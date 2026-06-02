<?php

namespace App\Services\IncidentProtection;

final class ProtectionActionExecutionResult
{
    public function __construct(
        public readonly string $status,
        public readonly ProtectionAction $action,
        public readonly string $message,
        public readonly bool $blocked = false,
        public readonly bool $dryRun = true,
        public readonly array $output = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'action' => $this->action->toArray(),
            'message' => $this->message,
            'blocked' => $this->blocked,
            'dry_run' => $this->dryRun,
            'output' => $this->output,
        ];
    }
}
