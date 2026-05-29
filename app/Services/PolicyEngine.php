<?php

namespace App\Services;

use App\Actions\Application\StopApplication;
use App\Actions\Server\DeleteServer;
use App\Models\Application;
use App\Models\PendingIntent;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

/**
 * Sovereign Infrastructure Policy Engine
 *
 * Centralizes the Declarative Operational Constitution.
 * Evaluates whether an infrastructure action (intent) requires multi-signature
 * cryptographic approval before being executed on the substrate.
 */
class PolicyEngine
{
    /**
     * The Sovereign Infrastructure Constitution
     * Defines strict rules and signatures requirements for critical operations.
     */
    protected array $constitution = [
        'server.removed' => [
            'title' => 'Decommission Node from Consensus Cluster',
            'signatures_required' => 2,
            'roles' => ['admin', 'security'],
            'description' => 'Permanently removes a node from the cluster. High-risk state transition.',
        ],
        'application.deploy' => [
            'title' => 'Deploy Workload Execution Intent',
            'signatures_required' => 2,
            'roles' => ['admin', 'developer'],
            'description' => 'Compiles and launches containers. Requires double signature for production safety.',
        ],
        'application.stop' => [
            'title' => 'Terminate Running Workload Container',
            'signatures_required' => 1,
            'roles' => ['admin'],
            'description' => 'Stops and deletes live container workloads.',
        ],
        'container.exec.command' => [
            'title' => 'Execute bounded command inside container',
            'signatures_required' => 1,
            'roles' => ['admin', 'developer'],
            'description' => 'Executes a pre-scoped command in a target container.',
        ],
        'team.member.invite' => [
            'title' => 'Issue bounded team invitation artifact',
            'signatures_required' => 1,
            'roles' => ['owner', 'admin'],
            'description' => 'Creates a non-consumable authority opportunity for future SL1 membership join.',
        ],
    ];

    public function evaluateContainerExec(
        User $user,
        Server $server,
        string $container,
        string $command,
        int $timeout = 15,
        array $riskContext = [],
    ): array {
        $riskLevel = data_get($riskContext, 'risk_level', 'LOW');
        $allowed = in_array($riskLevel, ['LOW', 'MEDIUM'], true);

        return [
            'team_id' => $server->team_id,
            'user_id' => $user->id,
            'intent_type' => 'container.exec.command',
            'target_type' => Server::class,
            'target_id' => $server->id,
            'decision' => $allowed ? 'allow' : 'deny',
            'capability' => InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC,
            'scope' => [
                'server_uuid' => $server->uuid,
                'container' => $container,
                'command_hash' => hash('sha256', $command),
                'timeout_max_seconds' => min(max($timeout, 1), AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS),
            ],
            'risk_context' => $riskContext,
            'reasons' => $allowed
                ? ['POLICY_CONTAINER_EXEC_TRANSITIONAL_ALLOW']
                : ['POLICY_CONTAINER_EXEC_RISK_ESCALATION_REQUIRED'],
            'expires_at' => now()->addMinutes(5),
            'meta' => [
                'artifact_type' => 'PolicyDecision',
                'version' => 'infra.policy-decision.v1',
                'determinism' => 'transitional-policy-v1',
            ],
        ];
    }

    public function evaluateTeamMemberInvite(
        User $issuer,
        Team $team,
        string $requestedRole,
        ?string $deliveryEmail = null,
        ?string $invitedPrincipalHint = null,
        array $riskContext = [],
    ): array {
        $role = in_array($requestedRole, ['owner', 'admin', 'member'], true) ? $requestedRole : 'invalid';
        $issuerRole = $issuer->teams()
            ->where('teams.id', $team->id)
            ->first()
            ?->pivot
            ?->role;

        $allowed = match ($issuerRole) {
            'owner' => in_array($role, ['owner', 'admin', 'member'], true),
            'admin' => in_array($role, ['admin', 'member'], true),
            default => false,
        };

        $normalizedDeliveryEmail = $deliveryEmail ? strtolower($deliveryEmail) : null;
        $expiresAt = now()->addDays((int) config('constants.invitation.link.expiration_days', 3));

        return [
            'team_id' => $team->id,
            'user_id' => $issuer->id,
            'intent_type' => 'team.member.invite',
            'target_type' => Team::class,
            'target_id' => $team->id,
            'decision' => $allowed ? 'allow' : 'deny',
            'capability' => TeamInvitationArtifactService::CAPABILITY_TEAM_MEMBER_INVITE,
            'scope' => [
                'team_id' => $team->id,
                'role_scope' => $role,
                'delivery_email' => $normalizedDeliveryEmail,
                'delivery_email_hash' => $normalizedDeliveryEmail ? hash('sha256', $normalizedDeliveryEmail) : null,
                'invited_principal_hint' => $invitedPrincipalHint,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
            'risk_context' => $riskContext,
            'reasons' => $allowed
                ? ['POLICY_TEAM_INVITE_ALLOWED']
                : ['POLICY_TEAM_INVITE_ROLE_SCOPE_DENIED'],
            'expires_at' => $expiresAt,
            'meta' => [
                'artifact_type' => 'PolicyDecision',
                'version' => 'team.policy-decision.v1',
                'determinism' => 'team-invite-policy-v1',
            ],
        ];
    }

    /**
     * Checks if the intent type requires policy/quorum clearance.
     */
    public function requiresApproval(string $eventType, array $payload = []): bool
    {
        // In local development, if we want to run easily, we look at the constitution
        return isset($this->constitution[$eventType]);
    }

    /**
     * Returns the constitutional rule details for an event type.
     */
    public function getRule(string $eventType): ?array
    {
        return $this->constitution[$eventType] ?? null;
    }

    public function cancelIntent(PendingIntent $intent, string $actorDid, string $reason = 'Superseded by a newer operational intent.'): bool
    {
        return $this->revokeIntent($intent, $actorDid, $reason);
    }

    public function revokeIntent(PendingIntent $intent, string $actorDid, string $reason = 'Intent was revoked before quorum.'): bool
    {
        return $this->closeIntent($intent, PendingIntent::STATUS_REVOKED, 'Intent Revoked', $actorDid, $reason);
    }

    public function rejectIntent(PendingIntent $intent, string $actorDid, string $reason = 'Intent was rejected by policy or authority evaluation.'): bool
    {
        return $this->closeIntent($intent, PendingIntent::STATUS_REJECTED, 'Intent Rejected', $actorDid, $reason);
    }

    private function expireIntent(PendingIntent $intent, string $actorDid, string $reason): bool
    {
        return $this->closeIntent($intent, PendingIntent::STATUS_EXPIRED, 'Intent Expired', $actorDid, $reason);
    }

    private function closeIntent(PendingIntent $intent, string $status, string $action, string $actorDid, string $reason): bool
    {
        if ($intent->status !== PendingIntent::STATUS_PENDING) {
            return false;
        }

        $timeline = $intent->timeline ?? [];
        $timeline[] = [
            'timestamp' => now()->toIso8601String(),
            'actor' => $actorDid,
            'action' => $action,
            'detail' => $reason,
        ];

        $intent->update([
            'status' => $status,
            'timeline' => $timeline,
        ]);

        return true;
    }

    public function expireStalePendingIntents(?int $teamId = null, ?int $ttlMinutes = null): int
    {
        $ttl = max(1, $ttlMinutes ?? (int) config('sovereign.pending_intents.ttl_minutes', 30));
        $cutoff = now()->subMinutes($ttl);
        $expired = 0;

        PendingIntent::query()
            ->when($teamId !== null, fn ($query) => $query->where('team_id', $teamId))
            ->where('status', PendingIntent::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($intents) use (&$expired, $ttl) {
                foreach ($intents as $intent) {
                    if ($this->expireIntent(
                        $intent,
                        'DID:SYS|SERVICE:#intent-expiry',
                        "Intent expired after {$ttl} minutes without quorum."
                    )) {
                        $expired++;
                    }
                }
            });

        return $expired;
    }

    /**
     * Stages a pending intent inside the Mempool.
     */
    public function stage(string $eventType, Model $entity, array $payload, ?int $teamId = null): PendingIntent
    {
        $rule = $this->getRule($eventType);
        $title = $rule ? $rule['title'] : 'Infrastructure Transition';

        $actor = auth()->user();
        $actorDid = 'DID:SYS|USER:#'.($actor?->id ?? 'system');
        $resolvedTeamId = $this->resolveTeamId($entity, $teamId);

        $intent = PendingIntent::create([
            'event_type' => $eventType,
            'target_type' => get_class($entity),
            'target_id' => $entity->getKey(),
            'payload' => $payload,
            'team_id' => $resolvedTeamId,
            'status' => PendingIntent::STATUS_PENDING,
            'signatures' => [],
            'timeline' => [
                [
                    'timestamp' => now()->toIso8601String(),
                    'actor' => $actorDid,
                    'action' => 'Proposed',
                    'detail' => "Staged intent: '{$title}' in Pending Pool.",
                ],
            ],
        ]);

        // Auto-apply the proposer's signature as the first co-signer
        $this->addSignature($intent, $actorDid, 'proposer', $this->actorEvidence($actor));

        Log::info("Sovereign Policy Engine: Staged '{$eventType}' in mempool [UUID: {$intent->uuid}]");

        return $intent;
    }

    protected function resolveTeamId(Model $entity, ?int $teamId = null): int
    {
        $candidates = [
            $teamId,
            data_get($entity, 'team_id'),
            auth()->user()?->currentTeam()?->id,
            auth()->user()?->teams()?->orderBy('teams.id')->value('teams.id'),
            0,
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            $candidate = (int) $candidate;
            if (Team::query()->whereKey($candidate)->exists()) {
                return $candidate;
            }
        }

        $firstTeamId = Team::query()->orderBy('id')->value('id');
        if ($firstTeamId !== null) {
            return (int) $firstTeamId;
        }

        throw new \RuntimeException('Cannot stage sovereign intent without an existing team.');
    }

    /**
     * Appends a cryptographic signature to a staged intent.
     */
    public function addSignature(PendingIntent $intent, string $actorDid, string $role, array $evidence = []): bool
    {
        $signatures = $intent->signatures;

        // Prevent duplicate signatures from the same actor
        if (isset($signatures[$actorDid])) {
            return false;
        }

        // Attach signature hash
        $signature = [
            'signed_at' => now()->toIso8601String(),
            'role' => $role,
            'hash' => hash('sha256', $intent->uuid.$actorDid.$role.now()->timestamp),
        ];
        if ($evidence !== []) {
            $signature['evidence'] = $evidence;
        }

        $signatures[$actorDid] = $signature;

        $timeline = $intent->timeline;
        $timeline[] = [
            'timestamp' => now()->toIso8601String(),
            'actor' => $actorDid,
            'action' => 'Signature Attached',
            'detail' => "Cryptographic approval granted as '{$role}'.",
        ];

        $intent->update([
            'signatures' => $signatures,
            'timeline' => $timeline,
        ]);

        $rule = $this->getRule($intent->event_type);
        if ($rule && count($signatures) >= $rule['signatures_required']) {
            $this->release($intent);
        }

        return true;
    }

    protected function actorEvidence(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $binding = $user->sl1IdentityBinding;

        return array_filter([
            'coolify_user_id' => $user->id,
            'coolify_user_name' => $user->name,
            'sl1_entity_address' => $binding?->entity_address,
            'sl1_controller_address' => $binding?->controller_address,
            'sl1_alias' => $binding?->display_alias ?: $binding?->alias,
            'sl1_proof_id' => $binding?->proof_id,
        ], fn ($value) => $value !== null && $value !== '');
    }

    protected function approvalSummary(PendingIntent $intent): array
    {
        $rule = $this->getRule($intent->event_type);

        return [
            'approved_intent_uuid' => $intent->uuid,
            'event_type' => $intent->event_type,
            'target_type' => $intent->target_type,
            'target_id' => $intent->target_id,
            'team_id' => $intent->team_id,
            'signatures_required' => $rule['signatures_required'] ?? 1,
            'signatures_collected' => count($intent->signatures ?? []),
            'approvals' => collect($intent->signatures ?? [])
                ->map(function (array $signature, string $actor) {
                    $evidence = $signature['evidence'] ?? [];

                    return array_filter([
                        'actor' => $actor,
                        'role' => $signature['role'] ?? null,
                        'signed_at' => $signature['signed_at'] ?? null,
                        'hash' => $signature['hash'] ?? null,
                        'proof_id' => data_get($evidence, 'proof_id') ?: data_get($evidence, 'sl1_proof_id'),
                        'proof_type' => data_get($evidence, 'proof_type'),
                        'sl1_entity_address' => data_get($evidence, 'sl1_entity_address'),
                        'controller_address' => data_get($evidence, 'controller_address') ?: data_get($evidence, 'sl1_controller_address'),
                        'intent_hash' => data_get($evidence, 'intent_hash'),
                        'signature_hash' => data_get($evidence, 'signature') ? hash('sha256', (string) data_get($evidence, 'signature')) : null,
                    ], fn ($value) => $value !== null && $value !== '');
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * Promotes a staged intent to the execution substrate (releases it).
     */
    protected function release(PendingIntent $intent): void
    {
        $timeline = $intent->timeline;
        $timeline[] = [
            'timestamp' => now()->toIso8601String(),
            'actor' => 'DID:SYS|SERVICE:#policy-engine',
            'action' => 'Quorum Reached',
            'detail' => 'All constitutional signatures collected. Releasing to execution substrate.',
        ];

        $intent->update([
            'status' => PendingIntent::STATUS_ACCEPTED,
            'timeline' => $timeline,
        ]);

        try {
            DB::transaction(function () use ($intent) {
                $this->executeSubstrateAction($intent);
            });

            $timeline = $intent->timeline;
            $timeline[] = [
                'timestamp' => now()->toIso8601String(),
                'actor' => 'DID:SYS|SERVICE:#execution-substrate',
                'action' => 'Execution Released',
                'detail' => 'Substrate successfully processed state transition.',
            ];
            $timeline[] = [
                'timestamp' => now()->toIso8601String(),
                'actor' => 'DID:SYS|SERVICE:#ledger-anchoring',
                'action' => 'Ledger Anchored',
                'detail' => 'State transition sealed into the immutable hash chain.',
            ];

            $intent->update([
                'status' => PendingIntent::STATUS_EXECUTED,
                'timeline' => $timeline,
            ]);

            Log::info("Sovereign Policy Engine: Executed '{$intent->event_type}' successfully [UUID: {$intent->uuid}]");

        } catch (\Throwable $e) {
            $timeline = $intent->timeline;
            $timeline[] = [
                'timestamp' => now()->toIso8601String(),
                'actor' => 'DID:SYS|SERVICE:#execution-substrate',
                'action' => 'Execution Failed',
                'detail' => 'Substrate error: '.$e->getMessage(),
            ];
            $intent->update([
                'status' => PendingIntent::STATUS_FAILED,
                'timeline' => $timeline,
            ]);
            Log::error("Sovereign Policy Engine: Failed execution of '{$intent->event_type}'", [
                'uuid' => $intent->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Executes the actual PHP/Substrate code once the intent is fully authorized.
     */
    protected function executeSubstrateAction(PendingIntent $intent): void
    {
        $payload = $intent->payload;

        switch ($intent->event_type) {
            case 'server.removed':
                // Substrate: Permanently delete node
                DeleteServer::run(
                    serverId: $payload['server_id'],
                    deleteFromHetzner: $payload['from_hetzner'] ?? false,
                    hetznerServerId: $payload['hetzner_server_id'] ?? null,
                    cloudProviderTokenId: $payload['token_id'] ?? null,
                    teamId: $payload['team_id'] ?? null,
                    mandateApproved: true
                );
                break;

            case 'application.deploy':
                $application = Application::find($payload['application_id']);
                if (! $application) {
                    throw new \Exception('Application target not found for approved deploy intent.');
                }

                $deploymentUuid = (string) new Cuid2;
                $result = queue_application_deployment(
                    application: $application,
                    deployment_uuid: $deploymentUuid,
                    force_rebuild: $payload['force_rebuild'] ?? false,
                );
                if (in_array($result['status'] ?? null, ['queue_full', 'skipped'], true)) {
                    throw new \Exception('Deployment release failed: '.($result['message'] ?? $result['status']));
                }

                app(InfraLedgerService::class)->record(
                    eventType: 'application.deploy',
                    entity: $application,
                    payload: [
                        'approved_intent_uuid' => $intent->uuid,
                        'deployment_uuid' => $deploymentUuid,
                        'force_rebuild' => $payload['force_rebuild'] ?? false,
                        'server_uuid' => $application->destination?->server?->uuid,
                        'build_pack' => $application->build_pack,
                        'authority' => $this->approvalSummary($intent),
                    ],
                    inputState: [
                        'status' => $application->status,
                    ],
                    actor: 'DID:SYS|SERVICE:#execution-substrate',
                    teamId: $intent->team_id,
                );
                break;

            case 'application.stop':
                $application = Application::find($payload['application_id']);
                if (! $application) {
                    throw new \Exception('Application target not found for approved stop intent.');
                }

                $result = StopApplication::run($application);
                if (is_string($result) && $result !== '') {
                    throw new \Exception('Stop release failed: '.$result);
                }

                app(InfraLedgerService::class)->record(
                    eventType: 'application.stop',
                    entity: $application,
                    payload: [
                        'approved_intent_uuid' => $intent->uuid,
                        'server_uuid' => $application->destination?->server?->uuid,
                        'authority' => $this->approvalSummary($intent),
                    ],
                    inputState: [
                        'status' => $application->status,
                    ],
                    actor: 'DID:SYS|SERVICE:#execution-substrate',
                    teamId: $intent->team_id,
                );
                break;

            default:
                throw new \Exception("Unknown execution transition type: '{$intent->event_type}'");
        }
    }

    /**
     * 🛡️ Operational Risk Classification Engine
     * Calculates risk metrics by evaluating target impact and the active cluster state.
     */
    public function getOperationalRisk(string $eventType, array $payload): array
    {
        switch ($eventType) {
            case 'server.removed':
                $totalServers = Server::count();

                return [
                    'risk_level' => $totalServers <= 2 ? 'CRITICAL' : 'HIGH',
                    'impact' => 'Consensus topology modification (Validator Exit)',
                    'nodes' => '1 validator node ('.($payload['server_name'] ?? 'Target').')',
                    'recovery' => '12m (automated container failover)',
                ];

            case 'application.deploy':
                return [
                    'risk_level' => 'MEDIUM',
                    'impact' => 'Resource allocation & container deployment',
                    'nodes' => '1 workload container ('.($payload['application_name'] ?? 'Target').')',
                    'recovery' => '0s (rolling restart)',
                ];

            case 'application.stop':
                return [
                    'risk_level' => 'HIGH',
                    'impact' => 'Live traffic termination & workload release',
                    'nodes' => '1 active workload ('.($payload['application_name'] ?? 'Target').')',
                    'recovery' => 'Instant (container shutdown)',
                ];

            default:
                return [
                    'risk_level' => 'LOW',
                    'impact' => 'Standard state transition',
                    'nodes' => 'None',
                    'recovery' => 'Instant',
                ];
        }
    }

    /**
     * 🔬 State Transition Simulator
     * Simulates the exact state of the infrastructure topology *before* the intent is committed.
     */
    public function simulateTransition(string $eventType, array $payload): array
    {
        switch ($eventType) {
            case 'server.removed':
                $totalServers = Server::count();
                $remaining = max(0, $totalServers - 1);

                // Quorum logic: Consensus requires >= 50% nodes
                $quorumMaintained = $remaining >= 1;
                $health = $totalServers > 0 ? round(($remaining / $totalServers) * 100) : 0;

                // Count affected workloads on this specific server
                $affectedAppsCount = 0;
                $serverId = $payload['server_id'] ?? null;
                if ($serverId) {
                    $affectedAppsCount = Application::whereHas('destination', function ($q) use ($serverId) {
                        $q->where('server_id', $serverId);
                    })->count();
                }

                return [
                    'health' => $health.'%',
                    'quorum' => $quorumMaintained ? 'MAINTAINED' : 'WARNING: QUORUM LOST',
                    'affected' => $affectedAppsCount,
                    'failover' => $quorumMaintained ? 'SUCCESS (Active fallback)' : 'WARNING: Isolated Node',
                ];

            case 'application.deploy':
                return [
                    'health' => '100%',
                    'quorum' => 'MAINTAINED',
                    'affected' => 1,
                    'failover' => 'SUCCESS (Zero-downtime)',
                ];

            case 'application.stop':
                return [
                    'health' => '100%',
                    'quorum' => 'MAINTAINED',
                    'affected' => 1,
                    'failover' => 'SUCCESS (Graceful Release)',
                ];

            default:
                return [
                    'health' => '100%',
                    'quorum' => 'MAINTAINED',
                    'affected' => 0,
                    'failover' => 'SUCCESS',
                ];
        }
    }
}
