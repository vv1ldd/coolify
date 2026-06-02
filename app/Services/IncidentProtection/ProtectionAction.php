<?php

namespace App\Services\IncidentProtection;

use App\Enums\ProtectionActionType;

final class ProtectionAction
{
    public readonly bool $dangerous;

    public function __construct(
        public readonly ProtectionActionType $type,
        public readonly string $label,
        public readonly array $target = [],
        public readonly array $payload = [],
        public readonly bool $requiresApproval = false,
        public readonly bool $dryRun = true,
        public readonly ?string $reason = null,
        ?bool $dangerous = null,
    ) {
        $this->dangerous = $dangerous ?? $type->requiresProviderApproval();
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'label' => $this->label,
            'target' => $this->target,
            'payload' => $this->payload,
            'requires_approval' => $this->requiresApproval,
            'dry_run' => $this->dryRun,
            'dangerous' => $this->dangerous,
            'reason' => $this->reason,
        ];
    }
}
