<?php

namespace App\Services\EdgeProtection;

use App\Models\EdgePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EdgePolicyService
{
    /**
     * @throws ValidationException
     */
    public function createForTeam(int $teamId, array $attributes): EdgePolicy
    {
        $payload = $this->validatedPayload($attributes);
        $payload['team_id'] = $teamId;

        return EdgePolicy::mutateThroughService(fn () => EdgePolicy::create($payload));
    }

    /**
     * @throws ValidationException
     */
    public function updateForTeam(int $teamId, string $uuid, array $attributes): EdgePolicy
    {
        $policy = EdgePolicy::whereTeamId($teamId)->whereUuid($uuid)->firstOrFail();
        $payload = $this->validatedPayload($attributes, partial: true, existing: $policy);

        return EdgePolicy::mutateThroughService(function () use ($policy, $payload): EdgePolicy {
            $policy->fill($payload);
            $policy->save();

            return $policy->refresh();
        });
    }

    public function deleteForTeam(int $teamId, string $uuid): void
    {
        $policy = EdgePolicy::whereTeamId($teamId)->whereUuid($uuid)->firstOrFail();

        EdgePolicy::mutateThroughService(fn () => $policy->delete());
    }

    public function resolveForRequest(Request $request, mixed $context = null): ResolvedEdgePolicy
    {
        $teamId = data_get($context, 'team_id') ?? (function_exists('currentTeam') ? currentTeam()?->id : null);

        return $this->resolveByDomain(is_numeric($teamId) ? (int) $teamId : null, $request->getHost());
    }

    public function resolveByDomain(?int $teamId, ?string $domain): ResolvedEdgePolicy
    {
        $domain = $this->normalizeHost((string) $domain);
        if ($domain === '') {
            return $this->teamDefaultOrConfig($teamId);
        }

        $domainPolicy = $this->domainPolicies($teamId)
            ->filter(fn (EdgePolicy $policy): bool => $this->domainMatchesPolicy($domain, $policy))
            ->sortByDesc(fn (EdgePolicy $policy): int => $this->policySpecificity($domain, $policy))
            ->first();

        if ($domainPolicy) {
            return ResolvedEdgePolicy::fromPolicy($domainPolicy);
        }

        return $this->teamDefaultOrConfig($teamId);
    }

    public function summariesForDomains(?int $teamId, ?string $domains): Collection
    {
        return $this->domainsFromList($domains)
            ->map(fn (string $domain): array => [
                'domain' => $domain,
                'policy' => $this->resolveByDomain($teamId, $domain),
            ])
            ->unique(fn (array $summary): string => $summary['domain'].'|'.$summary['policy']->source.'|'.$summary['policy']->uuid)
            ->values();
    }

    public function domainsFromList(?string $domains): Collection
    {
        return collect(explode(',', (string) $domains))
            ->map(fn (string $domain): ?string => $this->hostFromDomain($domain))
            ->filter()
            ->unique()
            ->values();
    }

    private function teamDefaultOrConfig(?int $teamId): ResolvedEdgePolicy
    {
        if ($teamId === null || ! Schema::hasTable('edge_policies')) {
            return ResolvedEdgePolicy::fromConfig();
        }

        $policy = EdgePolicy::whereTeamId($teamId)
            ->whereIn('scope_type', [EdgePolicy::SCOPE_TEAM, EdgePolicy::SCOPE_DEFAULT])
            ->whereNull('scope_value')
            ->orderByRaw("case when scope_type = 'team' then 0 else 1 end")
            ->first();

        return $policy ? ResolvedEdgePolicy::fromPolicy($policy) : ResolvedEdgePolicy::fromConfig();
    }

    private function domainPolicies(?int $teamId): Collection
    {
        if ($teamId === null || ! Schema::hasTable('edge_policies')) {
            return collect();
        }

        return EdgePolicy::query()
            ->whereTeamId($teamId)
            ->where('scope_type', EdgePolicy::SCOPE_DOMAIN)
            ->whereNotNull('scope_value')
            ->get();
    }

    private function domainMatchesPolicy(string $domain, EdgePolicy $policy): bool
    {
        $scope = $this->normalizeHost((string) $policy->scope_value);
        if ($scope === '') {
            return false;
        }

        if ($domain === $scope) {
            return true;
        }

        if (str_starts_with($scope, '*.')) {
            $suffix = substr($scope, 2);

            return $domain !== $suffix && str_ends_with($domain, '.'.$suffix);
        }

        return str_starts_with($scope, '.') && str_ends_with($domain, $scope);
    }

    private function policySpecificity(string $domain, EdgePolicy $policy): int
    {
        $scope = $this->normalizeHost((string) $policy->scope_value);
        if ($domain === $scope) {
            return 10_000 + strlen($scope);
        }

        return strlen(ltrim($scope, '*.'));
    }

    /**
     * @throws ValidationException
     */
    private function validatedPayload(array $attributes, bool $partial = false, ?EdgePolicy $existing = null): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'mode' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(EdgePolicy::MODES)],
            'scope_type' => [$partial ? 'sometimes' : 'required', 'string', Rule::in(EdgePolicy::SCOPE_TYPES)],
            'scope_value' => ['nullable', 'string', 'max:255'],
            'ruleset' => ['nullable', 'string', 'max:255'],
            'challenge_enabled' => ['sometimes', 'boolean'],
            'silent_drop_enabled' => ['sometimes', 'boolean'],
            'rate_limit_average' => ['nullable', 'integer', 'min:1'],
            'rate_limit_burst' => ['nullable', 'integer', 'min:1'],
            'in_flight_limit' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];

        $payload = Validator::make($attributes, $rules)->validate();
        $mode = (string) ($payload['mode'] ?? $existing?->mode ?? EdgePolicy::MODE_NORMAL);
        $scopeType = (string) ($payload['scope_type'] ?? $existing?->scope_type ?? EdgePolicy::SCOPE_DOMAIN);

        if (! array_key_exists('ruleset', $payload)) {
            $payload['ruleset'] = $existing?->ruleset ?? 'default_v1';
        }

        if (! array_key_exists('challenge_enabled', $payload)) {
            $payload['challenge_enabled'] = $existing?->challenge_enabled ?? $mode === EdgePolicy::MODE_UNDER_ATTACK;
        }

        if (! array_key_exists('silent_drop_enabled', $payload)) {
            $payload['silent_drop_enabled'] = $existing?->silent_drop_enabled ?? false;
        }

        if ($scopeType === EdgePolicy::SCOPE_DOMAIN && array_key_exists('scope_value', $payload)) {
            $payload['scope_value'] = $this->normalizeHost((string) $payload['scope_value']);
        }

        return $payload;
    }

    private function hostFromDomain(string $domain): ?string
    {
        $candidate = trim($domain);
        if ($candidate === '') {
            return null;
        }

        if (! str_contains($candidate, '://')) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->normalizeHost($host);
    }

    private function normalizeHost(string $host): string
    {
        $host = rtrim(trim($host), '.');

        return function_exists('mb_strtolower') ? mb_strtolower($host) : strtolower($host);
    }
}
