<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyDecision;
use App\Models\Server;
use App\Services\AgentContainerService;
use App\Services\InfraAuthorizationService;
use App\Services\InfraLedgerService;
use App\Services\PolicyEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use OpenApi\Attributes as OA;

class AgentContainersController extends Controller
{
    public function __construct(
        private readonly AgentContainerService $containers,
        private readonly InfraAuthorizationService $authorizations,
        private readonly InfraLedgerService $ledger,
        private readonly PolicyEngine $policy,
    ) {}

    #[OA\Get(
        summary: 'List agent containers',
        description: 'List Docker containers on a team-owned server for agent/Cursor integrations.',
        path: '/agent/containers',
        operationId: 'agent-list-containers',
        security: [['bearerAuth' => []]],
        tags: ['Agent']
    )]
    public function index(Request $request)
    {
        $server = $this->resolveServer($request);
        if ($server instanceof JsonResponse) {
            return $server;
        }

        if (! $server->isFunctional()) {
            return response()->json(['message' => 'Server is not reachable or usable.'], 422);
        }

        return response()->json(serializeApiResponse([
            'server_uuid' => $server->uuid,
            'containers' => $this->containers->list($server),
        ]));
    }

    #[OA\Get(
        summary: 'Get agent container logs',
        description: 'Fetch recent Docker logs from a team-owned server container.',
        path: '/agent/containers/{container}/logs',
        operationId: 'agent-container-logs',
        security: [['bearerAuth' => []]],
        tags: ['Agent']
    )]
    public function logs(Request $request, string $container)
    {
        $server = $this->resolveServer($request);
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $validator = Validator::make($request->query(), [
            'lines' => ['nullable', 'integer', 'min:1', 'max:'.AgentContainerService::MAX_LOG_LINES],
            'timestamps' => ['nullable', 'boolean'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        if (! $server->isFunctional()) {
            return response()->json(['message' => 'Server is not reachable or usable.'], 422);
        }

        try {
            return response()->json(serializeApiResponse([
                'server_uuid' => $server->uuid,
                'container' => $container,
                'logs' => $this->containers->logs(
                    server: $server,
                    container: $container,
                    lines: (int) $request->integer('lines', 200),
                    timestamps: $request->boolean('timestamps', true),
                ),
            ]));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    #[OA\Post(
        summary: 'Execute agent container command',
        description: 'Execute a shell-safe command inside a container. Requires a consumable AuthorizationArtifact.',
        path: '/agent/containers/{container}/exec',
        operationId: 'agent-container-exec',
        security: [['bearerAuth' => []]],
        tags: ['Agent']
    )]
    public function exec(Request $request, string $container)
    {
        $server = $this->resolveServer($request);
        if ($server instanceof JsonResponse) {
            return $server;
        }

        $validator = Validator::make($request->all(), [
            'command' => ['required', 'string', 'max:1000'],
            'timeout' => ['nullable', 'integer', 'min:1', 'max:'.AgentContainerService::MAX_EXEC_TIMEOUT_SECONDS],
            'authorization_id' => ['nullable', 'uuid'],
            'emergency' => ['nullable', 'boolean'],
            'emergency_reason' => ['nullable', 'string', 'max:500'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $authorizationId = $request->string('authorization_id')->toString();
        $isEmergency = $request->boolean('emergency');
        if ($isEmergency && ! $request->user()->tokenCan('root')) {
            return response()->json(['message' => 'Root API token is required for emergency container exec.'], 403);
        }

        if ($isEmergency && blank($request->input('emergency_reason'))) {
            return response()->json(['message' => 'Emergency execution requires an explicit reason.'], 422);
        }

        if (! $server->isFunctional()) {
            return response()->json(['message' => 'Server is not reachable or usable.'], 422);
        }

        try {
            $command = $request->string('command')->toString();
            $timeout = (int) $request->integer('timeout', 15);

            $authorization = null;
            if ($authorizationId !== '') {
                $authorization = $this->authorizations->consumeContainerExec(
                    authorizationId: $authorizationId,
                    user: $request->user(),
                    server: $server,
                    container: $container,
                    command: $command,
                    timeout: $timeout,
                );
            } elseif (! $isEmergency) {
                $decision = PolicyDecision::create($this->policy->evaluateContainerExec(
                    user: $request->user(),
                    server: $server,
                    container: $container,
                    command: $command,
                    timeout: $timeout,
                    riskContext: [
                        'risk_level' => 'LOW',
                        'source' => 'agent-container-exec-api',
                    ],
                ));

                if (! $decision->allows(InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC)) {
                    return response()->json([
                        'message' => 'PolicyDecision does not allow container exec.',
                        'policy_decision_id' => $decision->uuid,
                    ], 403);
                }

                $authorization = $this->authorizations->issueContainerExec(
                    decision: $decision,
                    server: $server,
                    container: $container,
                    command: $command,
                    timeout: $timeout,
                );

                $authorization = $this->authorizations->consumeContainerExec(
                    authorizationId: $authorization->uuid,
                    user: $request->user(),
                    server: $server,
                    container: $container,
                    command: $command,
                    timeout: $timeout,
                );
            } else {
                $this->authorizations->recordEmergencyContainerExec(
                    user: $request->user(),
                    server: $server,
                    container: $container,
                    command: $command,
                    reason: $request->string('emergency_reason')->toString(),
                );
            }

            $output = $this->containers->exec(
                server: $server,
                container: $container,
                command: $command,
                timeout: $timeout,
            );

            if ($authorization) {
                $this->ledger->record(
                    eventType: InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC,
                    entity: $server,
                    payload: [
                        'capability' => InfraAuthorizationService::CAPABILITY_CONTAINER_EXEC,
                        'server_uuid' => $server->uuid,
                        'container' => $container,
                        'command_hash' => hash('sha256', $command),
                    ],
                    inputState: [
                        'policy_decision_id' => $authorization->policyDecision?->uuid,
                        'authorization_artifact_id' => $authorization->uuid,
                        'authorization_status' => $authorization->status,
                    ],
                    outputState: [
                        'executed' => true,
                        'output_hash' => hash('sha256', $output),
                    ],
                    actor: 'DID:SYS|USER:#'.$request->user()->id,
                    teamId: $server->team_id,
                );
            }

            return response()->json(serializeApiResponse([
                'server_uuid' => $server->uuid,
                'container' => $container,
                'authorization_id' => $authorization?->uuid,
                'emergency' => $isEmergency,
                'output' => $output,
            ]));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function resolveServer(Request $request): Server|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = Validator::make($request->all(), [
            'server_uuid' => ['required', 'string'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $server = Server::whereTeamId($teamId)->whereUuid($request->input('server_uuid'))->first();
        if (! $server) {
            return response()->json(['message' => 'Server not found.'], 404);
        }

        return $server;
    }
}
