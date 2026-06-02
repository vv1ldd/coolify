<?php

namespace App\Services\EdgeProtection;

use Illuminate\Http\Request;

class EdgeRequestClassifier
{
    public function __construct(
        private readonly EdgeProtectionService $edge,
        private readonly EdgePolicyService $policies,
    ) {}

    public function classify(Request $request, mixed $context = null): EdgeProtectionDecision
    {
        if ($this->isChallengeRoute($request)) {
            return EdgeProtectionDecision::allow('edge challenge route');
        }

        $policy = $this->policies->resolveForRequest($request, $context);
        if ($policy->source === 'config' && ! (bool) config('sovereign.edge_protection.enabled', true)) {
            return EdgeProtectionDecision::allow('edge protection disabled');
        }

        $context = [
            'host' => $request->getHost(),
            'path' => $request->path(),
            'mode' => $policy->mode,
            'policy_uuid' => $policy->uuid,
            'policy_source' => $policy->source,
        ];

        if ($policy->source !== 'config' && $policy->isOff()) {
            return EdgeProtectionDecision::allow('edge policy off', $context);
        }

        $isProbePath = $this->isProbePath($request);
        $isBadUserAgent = $this->isBadUserAgent($request);

        if ($policy->source === 'config' && $isProbePath && (bool) config('sovereign.edge_protection.block_probe_paths', true)) {
            return EdgeProtectionDecision::block('probe path', $this->blockStatus(), $context);
        }

        if ($policy->source === 'config' && $isBadUserAgent && (bool) config('sovereign.edge_protection.block_bad_user_agents', true)) {
            return EdgeProtectionDecision::block('bad user agent', $this->blockStatus(), $context);
        }

        if ($policy->source !== 'config' && $policy->silentDropEnabled && ($isProbePath || $isBadUserAgent)) {
            return EdgeProtectionDecision::block($isProbePath ? 'probe path' : 'bad user agent', $this->blockStatus(), $context);
        }

        if ($this->edge->hasValidProof($request)) {
            return EdgeProtectionDecision::allow('valid proof', $context);
        }

        if ($policy->challengesHostileRequests()) {
            if ($isProbePath) {
                return EdgeProtectionDecision::challenge('probe path', $context);
            }

            if ($isBadUserAgent) {
                return EdgeProtectionDecision::challenge('bad user agent', $context);
            }
        }

        if ($policy->challengesAllRequests()) {
            return EdgeProtectionDecision::challenge('missing proof', $context);
        }

        return EdgeProtectionDecision::allow('challenge mode inactive', $context);
    }

    private function isChallengeRoute(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        return $path === $this->edge->challengePath() || $path === $this->edge->verifyPath();
    }

    private function blockStatus(): int
    {
        $status = (int) config('sovereign.edge_protection.block_status', 404);

        return in_array($status, [403, 404], true) ? $status : 404;
    }

    private function isBadUserAgent(Request $request): bool
    {
        $settings = config('sovereign.traffic_filter', []);
        if (! (bool) data_get($settings, 'user_agent_filter_enabled', true)) {
            return false;
        }

        $userAgent = (string) $request->userAgent();
        if ($userAgent === '') {
            return (bool) data_get($settings, 'block_empty_user_agent', false);
        }

        foreach ((array) data_get($settings, 'suspicious_user_agent_patterns', []) as $pattern) {
            $pattern = trim((string) $pattern);
            if ($pattern !== '' && stripos($userAgent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function isProbePath(Request $request): bool
    {
        $settings = config('sovereign.traffic_filter', []);
        if (! (bool) data_get($settings, 'probe_path_filter_enabled', true)) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');
        foreach ((array) data_get($settings, 'suspicious_path_prefixes', []) as $prefix) {
            $prefix = trim((string) $prefix);
            if ($prefix === '') {
                continue;
            }

            $prefix = str_starts_with($prefix, '/') ? $prefix : "/{$prefix}";
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
