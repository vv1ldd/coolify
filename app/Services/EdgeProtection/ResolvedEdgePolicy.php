<?php

namespace App\Services\EdgeProtection;

use App\Models\EdgePolicy;

final class ResolvedEdgePolicy
{
    public function __construct(
        public readonly ?int $teamId,
        public readonly ?string $uuid,
        public readonly string $name,
        public readonly string $mode,
        public readonly string $scopeType,
        public readonly ?string $scopeValue,
        public readonly ?string $ruleset,
        public readonly bool $challengeEnabled,
        public readonly bool $silentDropEnabled,
        public readonly ?int $rateLimitAverage,
        public readonly ?int $rateLimitBurst,
        public readonly ?int $inFlightLimit,
        public readonly array $metadata = [],
        public readonly string $source = 'policy',
    ) {}

    public static function fromPolicy(EdgePolicy $policy): self
    {
        return new self(
            teamId: $policy->team_id,
            uuid: $policy->uuid,
            name: $policy->name,
            mode: $policy->mode,
            scopeType: $policy->scope_type,
            scopeValue: $policy->scope_value,
            ruleset: $policy->ruleset,
            challengeEnabled: (bool) $policy->challenge_enabled,
            silentDropEnabled: (bool) $policy->silent_drop_enabled,
            rateLimitAverage: $policy->rate_limit_average,
            rateLimitBurst: $policy->rate_limit_burst,
            inFlightLimit: $policy->in_flight_limit,
            metadata: $policy->metadata ?? [],
        );
    }

    public static function fromConfig(): self
    {
        $mode = strtolower((string) config('sovereign.edge_protection.mode', EdgePolicy::MODE_OFF));

        return new self(
            teamId: null,
            uuid: null,
            name: 'Config fallback',
            mode: $mode,
            scopeType: 'config',
            scopeValue: null,
            ruleset: 'config',
            challengeEnabled: in_array($mode, ['challenge', EdgePolicy::MODE_UNDER_ATTACK], true),
            silentDropEnabled: (bool) config('sovereign.edge_protection.block_bad_user_agents', true)
                || (bool) config('sovereign.edge_protection.block_probe_paths', true),
            rateLimitAverage: data_get(traefikTrafficFilterSettings(), 'rate_limit_average') !== null ? (int) data_get(traefikTrafficFilterSettings(), 'rate_limit_average') : null,
            rateLimitBurst: data_get(traefikTrafficFilterSettings(), 'rate_limit_burst') !== null ? (int) data_get(traefikTrafficFilterSettings(), 'rate_limit_burst') : null,
            inFlightLimit: data_get(traefikTrafficFilterSettings(), 'in_flight_request_limit') !== null ? (int) data_get(traefikTrafficFilterSettings(), 'in_flight_request_limit') : null,
            metadata: [],
            source: 'config',
        );
    }

    public function isOff(): bool
    {
        return $this->mode === EdgePolicy::MODE_OFF;
    }

    public function challengesAllRequests(): bool
    {
        return $this->challengeEnabled || in_array($this->mode, ['challenge', EdgePolicy::MODE_UNDER_ATTACK], true);
    }

    public function challengesHostileRequests(): bool
    {
        return $this->challengesAllRequests() || $this->mode === EdgePolicy::MODE_STRICT;
    }

    public function displayMode(): string
    {
        return str_replace('_', ' ', $this->mode);
    }
}
