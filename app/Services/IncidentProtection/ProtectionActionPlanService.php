<?php

namespace App\Services\IncidentProtection;

use App\Enums\ProtectionActionType;
use App\Enums\ProtectionLevel;
use App\Models\EdgePolicy;
use App\Services\InfraLedgerService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ProtectionActionPlanService
{
    public function __construct(
        private readonly ?ProtectionRunbook $runbook = null,
        private readonly ?InfraLedgerService $ledger = null,
    ) {}

    /**
     * @param  array{
     *     level?: string|null,
     *     severity?: int|string|null,
     *     signals?: array<int, string>|string|null,
     *     domain?: string|null,
     *     server_id?: int|string|null,
     *     application_id?: int|string|null,
     *     trace_id?: string|null
     * }  $input
     */
    public function plan(int $teamId, array $input): ProtectionActionPlan
    {
        $severity = min(max((int) data_get($input, 'severity', 0), 0), 100);
        $level = ProtectionLevel::fromSignalSeverity(data_get($input, 'level') ?: $severity);
        $signals = $this->signals($input);
        $context = $this->context($input);

        $plan = new ProtectionActionPlan(
            teamId: $teamId,
            level: $level,
            severity: $severity,
            signals: $signals,
            context: $context,
            actions: array_values(array_filter(array_map(
                fn (string $action): ?ProtectionAction => $this->actionFor($action, $level, $signals, $context),
                $this->runbook()->actionsForLevel($level),
            ))),
            runbook: $this->runbook()->name(),
            planId: (string) Str::uuid(),
        );

        $this->recordPlan($plan);

        return $plan;
    }

    private function actionFor(string $action, ProtectionLevel $level, array $signals, array $context): ?ProtectionAction
    {
        $type = ProtectionActionType::tryFrom($action);
        if (! $type) {
            return null;
        }

        $levelConfig = $this->runbook()->forLevel($level);
        $target = array_filter([
            'domain' => data_get($context, 'domain'),
            'server_id' => data_get($context, 'server_id'),
            'application_id' => data_get($context, 'application_id'),
        ], fn (mixed $value): bool => filled($value));

        return match ($type) {
            ProtectionActionType::SET_EDGE_POLICY_MODE => new ProtectionAction(
                type: $type,
                label: 'Set EdgePolicy mode to under attack',
                target: $target,
                payload: [
                    'mode' => data_get($levelConfig, 'edge_policy_mode', EdgePolicy::MODE_UNDER_ATTACK),
                    'owner' => 'EdgePolicy',
                ],
                dryRun: true,
                reason: 'L7 behavior remains owned by EdgePolicy.',
            ),
            ProtectionActionType::ENABLE_CHALLENGE => new ProtectionAction(
                type: $type,
                label: 'Enable edge challenge',
                target: $target,
                payload: [
                    'challenge_enabled' => true,
                    'owner' => 'EdgePolicy',
                ],
                dryRun: true,
                reason: 'Challenge state is planned for EdgePolicy execution.',
            ),
            ProtectionActionType::TIGHTEN_RATE_LIMIT => new ProtectionAction(
                type: $type,
                label: 'Tighten edge rate limits',
                target: $target,
                payload: [
                    'rate_limit_average' => (int) data_get($levelConfig, 'rate_limit_average', 40),
                    'rate_limit_burst' => (int) data_get($levelConfig, 'rate_limit_burst', 80),
                    'owner' => 'EdgePolicy',
                ],
                dryRun: true,
                reason: 'Rate-limit mutation is delegated to EdgePolicy.',
            ),
            ProtectionActionType::DNS_FAILOVER_PLAN => new ProtectionAction(
                type: $type,
                label: 'Prepare DNS failover plan',
                target: $target,
                payload: [
                    'owner' => 'DNS Steering',
                    'planned_only' => true,
                ],
                dryRun: true,
                reason: 'DNS changes are planned by incident response and executed by DNS Steering.',
            ),
            ProtectionActionType::NOTIFY => new ProtectionAction(
                type: $type,
                label: 'Notify operators',
                target: $target,
                payload: [
                    'level' => $level->value,
                    'signals' => $signals,
                ],
                dryRun: false,
                reason: 'Operators should review and approve any dangerous follow-up.',
            ),
            ProtectionActionType::PROVIDER_ISOLATE_SERVER,
            ProtectionActionType::PROVIDER_POWEROFF_SERVER => new ProtectionAction(
                type: $type,
                label: $type === ProtectionActionType::PROVIDER_ISOLATE_SERVER
                    ? 'Plan provider server isolation'
                    : 'Plan provider server poweroff',
                target: $target,
                payload: [
                    'owner' => 'Server/provider adapter',
                    'provider' => data_get($context, 'provider'),
                    'adapter_required' => true,
                    'implemented_adapters' => ['selectel_vds', 'hostinger_vps'],
                ],
                requiresApproval: true,
                dryRun: true,
                reason: 'Provider host actions are destructive and require explicit approval.',
                dangerous: true,
            ),
        };
    }

    private function runbook(): ProtectionRunbook
    {
        return $this->runbook ?: app(ProtectionRunbook::class);
    }

    private function ledger(): InfraLedgerService
    {
        return $this->ledger ?: app(InfraLedgerService::class);
    }

    /**
     * @return array<int, string>
     */
    private function signals(array $input): array
    {
        $signals = data_get($input, 'signals', []);
        if (is_string($signals)) {
            $signals = [$signals];
        }

        return array_values(array_filter(array_map('strval', (array) $signals)));
    }

    /**
     * @return array<string, mixed>
     */
    private function context(array $input): array
    {
        return array_filter([
            'domain' => data_get($input, 'domain'),
            'server_id' => data_get($input, 'server_id'),
            'application_id' => data_get($input, 'application_id'),
            'provider' => data_get($input, 'provider'),
            'trace_id' => data_get($input, 'trace_id'),
        ], fn (mixed $value): bool => filled($value));
    }

    private function recordPlan(ProtectionActionPlan $plan): void
    {
        if (! Schema::hasTable('infra_ledger')) {
            return;
        }

        try {
            $this->ledger()->record(
                eventType: 'protection.action_plan.created',
                payload: [
                    'plan_id' => $plan->id(),
                    'level' => $plan->level->value,
                    'severity' => $plan->severity,
                    'signals' => $plan->signals,
                    'context' => $plan->context,
                    'actions' => array_map(
                        fn (ProtectionAction $action): array => [
                            'type' => $action->type->value,
                            'requires_approval' => $action->requiresApproval,
                            'dry_run' => $action->dryRun,
                            'dangerous' => $action->dangerous,
                        ],
                        $plan->actions,
                    ),
                ],
                outputState: ['planned' => true],
                actor: 'DID:SYS|SERVICE:#incident-protection',
                teamId: $plan->teamId,
            );
        } catch (\Throwable $e) {
            Log::warning('Protection action plan ledger recording failed.', [
                'plan_id' => $plan->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
