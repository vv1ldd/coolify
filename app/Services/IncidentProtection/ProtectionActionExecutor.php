<?php

namespace App\Services\IncidentProtection;

use App\Enums\ProtectionActionType;
use App\Models\Server;
use App\Services\InfraLedgerService;
use App\Services\Provider\NoopProviderServerActionAdapter;
use App\Services\Provider\ProviderServerActionAdapter;
use App\Services\Provider\ProviderServerActionAdapterFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class ProtectionActionExecutor
{
    public function __construct(
        private readonly ?ProviderServerActionAdapter $providerAdapter = null,
        private readonly ?ProviderServerActionAdapterFactory $providerAdapterFactory = null,
        private readonly ?InfraLedgerService $ledger = null,
    ) {}

    /**
     * @param  array{
     *     team_id?: int|string|null,
     *     approved?: bool|null,
     *     approval_token?: string|null,
     *     force_provider_execution?: bool|null
     * }  $options
     */
    public function execute(ProtectionAction $action, array $options = []): ProtectionActionExecutionResult
    {
        if ($action->dangerous && ! $this->hasExplicitApproval($options)) {
            return $this->recordResult(new ProtectionActionExecutionResult(
                status: 'blocked',
                action: $action,
                message: 'Destructive provider action blocked: explicit approval flag and token are required.',
                blocked: true,
                dryRun: true,
            ), $options);
        }

        if ($action->dangerous && $this->providerDryRunEnabled($options)) {
            return $this->recordResult(new ProtectionActionExecutionResult(
                status: 'dry_run',
                action: $action,
                message: 'Provider action approved but kept as dry-run by environment/config guard.',
                blocked: false,
                dryRun: true,
            ), $options);
        }

        if ($action->type->requiresProviderApproval()) {
            return $this->executeProviderAction($action, $options);
        }

        return $this->recordResult(new ProtectionActionExecutionResult(
            status: 'planned',
            action: $action,
            message: 'Action is planned for its owning subsystem; no direct mutation was performed.',
            blocked: false,
            dryRun: $action->dryRun,
        ), $options);
    }

    private function executeProviderAction(ProtectionAction $action, array $options): ProtectionActionExecutionResult
    {
        $serverId = data_get($action->target, 'server_id');
        $server = filled($serverId) ? Server::find($serverId) : null;

        if (! $server) {
            return $this->recordResult(new ProtectionActionExecutionResult(
                status: 'blocked',
                action: $action,
                message: 'Provider action blocked: target server was not found.',
                blocked: true,
                dryRun: true,
            ), $options);
        }

        $output = match ($action->type) {
            ProtectionActionType::PROVIDER_ISOLATE_SERVER => $this->providerAdapterFor($server)->isolateServer($server, $action),
            ProtectionActionType::PROVIDER_POWEROFF_SERVER => $this->providerAdapterFor($server)->powerOffServer($server, $action),
            default => [],
        };

        return $this->recordResult(new ProtectionActionExecutionResult(
            status: 'executed',
            action: $action,
            message: 'Provider adapter accepted the action.',
            blocked: false,
            dryRun: false,
            output: $output,
        ), $options);
    }

    private function hasExplicitApproval(array $options): bool
    {
        return (bool) data_get($options, 'approved', false)
            && filled(data_get($options, 'approval_token'));
    }

    private function providerDryRunEnabled(array $options): bool
    {
        if (! (bool) data_get($options, 'force_provider_execution', false)) {
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        return (bool) config('sovereign.incident_protection.dry_run_provider_actions', true);
    }

    private function providerAdapterFor(Server $server): ProviderServerActionAdapter
    {
        if ($this->providerAdapter) {
            return $this->providerAdapter;
        }

        $cloudProviderToken = $server->cloudProviderToken;
        if (! $cloudProviderToken) {
            return new NoopProviderServerActionAdapter;
        }

        return $this->providerAdapterFactory()->make($cloudProviderToken->provider, $cloudProviderToken);
    }

    private function providerAdapterFactory(): ProviderServerActionAdapterFactory
    {
        return $this->providerAdapterFactory ?: app(ProviderServerActionAdapterFactory::class);
    }

    private function ledger(): InfraLedgerService
    {
        return $this->ledger ?: app(InfraLedgerService::class);
    }

    private function recordResult(ProtectionActionExecutionResult $result, array $options): ProtectionActionExecutionResult
    {
        if (! Schema::hasTable('infra_ledger')) {
            return $result;
        }

        try {
            $this->ledger()->record(
                eventType: 'protection.action.'.$result->status,
                payload: [
                    'type' => $result->action->type->value,
                    'requires_approval' => $result->action->requiresApproval,
                    'dangerous' => $result->action->dangerous,
                    'dry_run' => $result->dryRun,
                    'target' => $result->action->target,
                    'approval_present' => filled(data_get($options, 'approval_token')),
                ],
                outputState: [
                    'status' => $result->status,
                    'blocked' => $result->blocked,
                    'message' => $result->message,
                ],
                actor: 'DID:SYS|SERVICE:#incident-protection',
                teamId: filled(data_get($options, 'team_id')) ? (int) data_get($options, 'team_id') : null,
            );
        } catch (\Throwable $e) {
            Log::warning('Protection action execution ledger recording failed.', [
                'action' => $result->action->type->value,
                'status' => $result->status,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }
}
