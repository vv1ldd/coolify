<?php

namespace App\Enums;

enum ProtectionActionType: string
{
    case SET_EDGE_POLICY_MODE = 'set_edge_policy_mode';
    case ENABLE_CHALLENGE = 'enable_challenge';
    case TIGHTEN_RATE_LIMIT = 'tighten_rate_limit';
    case DNS_FAILOVER_PLAN = 'dns_failover_plan';
    case NOTIFY = 'notify';
    case PROVIDER_ISOLATE_SERVER = 'provider_isolate_server';
    case PROVIDER_POWEROFF_SERVER = 'provider_poweroff_server';

    public function requiresProviderApproval(): bool
    {
        return in_array($this, [
            self::PROVIDER_ISOLATE_SERVER,
            self::PROVIDER_POWEROFF_SERVER,
        ], true);
    }
}
