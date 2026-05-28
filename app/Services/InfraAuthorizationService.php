<?php

namespace App\Services;

use App\Models\InfraAuthorization;
use App\Models\PolicyDecision;
use App\Models\Server;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class InfraAuthorizationService
{
    public const CAPABILITY_CONTAINER_EXEC = 'container.exec.command';

    public function issueContainerExec(
        PolicyDecision $decision,
        Server $server,
        string $container,
        string $command,
        int $ttlSeconds = 300,
        ?int $timeout = null,
    ): InfraAuthorization {
        $this->validateContainerExecScope($container, $command);
        $this->assertPolicyDecisionAllowsContainerExec($decision, $server, $container, $command, $timeout);

        $ttlSeconds = min(max($ttlSeconds, 30), 900);
        $timeout = min(max($timeout ?? AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS, 1), AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS);
        $commandHash = hash('sha256', $command);

        return InfraAuthorization::create([
            'team_id' => $server->team_id,
            'user_id' => $decision->user_id,
            'policy_decision_id' => $decision->id,
            'capability' => self::CAPABILITY_CONTAINER_EXEC,
            'target_type' => Server::class,
            'target_id' => $server->id,
            'scope' => [
                'server_uuid' => $server->uuid,
                'container' => $container,
                'command_hash' => $commandHash,
                'timeout_max_seconds' => $timeout,
            ],
            'policy_decision' => [
                'uuid' => $decision->uuid,
                'decision' => $decision->decision,
                'reasons' => $decision->reasons,
            ],
            'risk_context' => $decision->risk_context,
            'replay_key' => hash('sha256', implode('|', [
                self::CAPABILITY_CONTAINER_EXEC,
                $server->uuid,
                $container,
                $commandHash,
                now()->timestamp,
                random_bytes(16),
            ])),
            'status' => 'issued',
            'expires_at' => now()->addSeconds($ttlSeconds),
            'meta' => [
                'artifact_type' => 'AuthorizationArtifact',
                'version' => 'infra.authorization.v1',
            ],
        ]);
    }

    public function consumeContainerExec(
        string $authorizationId,
        User $user,
        Server $server,
        string $container,
        string $command,
        int $timeout,
    ): InfraAuthorization {
        $this->validateContainerExecScope($container, $command);

        return DB::transaction(function () use ($authorizationId, $user, $server, $container, $command, $timeout) {
            $authorization = InfraAuthorization::query()
                ->where('uuid', $authorizationId)
                ->lockForUpdate()
                ->first();

            if (! $authorization) {
                throw new InvalidArgumentException('AuthorizationArtifact not found.');
            }

            if ($authorization->status !== 'issued') {
                throw new InvalidArgumentException('AuthorizationArtifact has already been consumed or revoked.');
            }

            if ($authorization->expires_at->isPast()) {
                $authorization->forceFill(['status' => 'expired'])->save();
                throw new InvalidArgumentException('AuthorizationArtifact has expired.');
            }

            if ($authorization->team_id !== $server->team_id) {
                throw new InvalidArgumentException('AuthorizationArtifact team mismatch.');
            }

            if ($authorization->capability !== self::CAPABILITY_CONTAINER_EXEC) {
                throw new InvalidArgumentException('AuthorizationArtifact capability mismatch.');
            }

            if ($authorization->target_type !== Server::class || (int) $authorization->target_id !== (int) $server->id) {
                throw new InvalidArgumentException('AuthorizationArtifact target mismatch.');
            }

            if (data_get($authorization->scope, 'container') !== $container) {
                throw new InvalidArgumentException('AuthorizationArtifact container scope mismatch.');
            }

            if (data_get($authorization->scope, 'command_hash') !== hash('sha256', $command)) {
                throw new InvalidArgumentException('AuthorizationArtifact command scope mismatch.');
            }

            if ($timeout > (int) data_get($authorization->scope, 'timeout_max_seconds', AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS)) {
                throw new InvalidArgumentException('AuthorizationArtifact timeout scope mismatch.');
            }

            $authorization->forceFill([
                'status' => 'consumed',
                'consumed_at' => now(),
                'consumed_by_user_id' => $user->id,
            ])->save();

            return $authorization;
        });
    }

    public function recordEmergencyContainerExec(
        User $user,
        Server $server,
        string $container,
        string $command,
        string $reason,
    ): void {
        app(InfraLedgerService::class)->record(
            eventType: 'constitutional.violation.emergency_exec',
            entity: $server,
            payload: [
                'capability' => self::CAPABILITY_CONTAINER_EXEC,
                'server_uuid' => $server->uuid,
                'container' => $container,
                'command_hash' => hash('sha256', $command),
                'reason' => $reason,
            ],
            inputState: [
                'authorization_artifact' => null,
                'emergency_mode' => true,
            ],
            outputState: [
                'approved_for_execution' => true,
                'constitutional_violation' => true,
            ],
            actor: 'DID:SYS|USER:#'.$user->id,
            teamId: $server->team_id,
        );
    }

    private function validateContainerExecScope(string $container, string $command): void
    {
        if (! ValidationPatterns::isValidContainerName($container)) {
            throw new InvalidArgumentException('Invalid container name.');
        }

        if (! preg_match(ValidationPatterns::SHELL_SAFE_COMMAND_PATTERN, $command)) {
            throw new InvalidArgumentException('Command contains shell-unsafe characters.');
        }
    }

    private function assertPolicyDecisionAllowsContainerExec(
        PolicyDecision $decision,
        Server $server,
        string $container,
        string $command,
        ?int $timeout,
    ): void {
        if (! $decision->allows(self::CAPABILITY_CONTAINER_EXEC)) {
            throw new InvalidArgumentException('PolicyDecision does not allow container exec.');
        }

        if ($decision->team_id !== $server->team_id) {
            throw new InvalidArgumentException('PolicyDecision team mismatch.');
        }

        if ($decision->target_type !== Server::class || (int) $decision->target_id !== (int) $server->id) {
            throw new InvalidArgumentException('PolicyDecision target mismatch.');
        }

        if (data_get($decision->scope, 'container') !== $container) {
            throw new InvalidArgumentException('PolicyDecision container scope mismatch.');
        }

        if (data_get($decision->scope, 'command_hash') !== hash('sha256', $command)) {
            throw new InvalidArgumentException('PolicyDecision command scope mismatch.');
        }

        if ($timeout !== null && $timeout > (int) data_get($decision->scope, 'timeout_max_seconds', AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS)) {
            throw new InvalidArgumentException('PolicyDecision timeout scope mismatch.');
        }
    }
}
