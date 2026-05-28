<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SecurityObservation;
use App\Services\SecurityObservationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class SecurityObservationsController extends Controller
{
    public function __construct(private readonly SecurityObservationService $observations) {}

    #[OA\Get(
        summary: 'List security observations',
        description: 'List recorded threat/security observations for the current team.',
        path: '/security/observations',
        operationId: 'list-security-observations',
        security: [['bearerAuth' => []]],
        tags: ['Security']
    )]
    public function index(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = Validator::make($request->query(), [
            'source_hash' => ['nullable', 'string', 'size:64'],
            'trace_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'session_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'signal' => ['nullable', 'string', 'max:128'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $observations = SecurityObservation::ownedByTeam((int) $teamId)
            ->when($request->query('source_hash'), fn ($query, string $sourceHash) => $query->where('source_hash', $sourceHash))
            ->when($request->query('trace_id'), fn ($query, string $traceId) => $query->where('trace_id', $traceId))
            ->when($request->query('session_id'), fn ($query, string $sessionId) => $query->where('session_id', $sessionId))
            ->when($request->query('signal'), fn ($query, string $signal) => $query->where('signal', $signal))
            ->latest('observed_at')
            ->limit((int) $request->integer('limit', 100))
            ->get()
            ->makeHidden(['id', 'team_id', 'source_ip']);

        return response()->json(serializeApiResponse($observations));
    }

    #[OA\Post(
        summary: 'Record security observation',
        description: 'Record a threat signal from edge filters, proxy logs, agents, or Cursor debugging integrations.',
        path: '/security/observations',
        operationId: 'create-security-observation',
        security: [['bearerAuth' => []]],
        tags: ['Security']
    )]
    public function store(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = Validator::make($request->all(), [
            'source_ip' => ['nullable', 'ip'],
            'trace_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'session_id' => ['nullable', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'layer' => ['nullable', Rule::in(['L0', 'L1', 'L2', 'L3'])],
            'signal' => ['required', 'string', 'max:128', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'score' => ['required', 'integer', 'min:0', 'max:100'],
            'action' => ['nullable', Rule::in(['observe', 'allow', 'challenge', 'block', 'rate_limited'])],
            'method' => ['nullable', 'string', 'max:16', 'regex:/^[A-Z]+$/'],
            'path' => ['nullable', 'string', 'max:2048'],
            'user_agent' => ['nullable', 'string', 'max:512'],
            'metadata' => ['nullable', 'array'],
            'observed_at' => ['nullable', 'date'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $payload = $validator->validated();
        $payload['source_ip'] ??= $request->ip();

        $observation = $this->observations->record((int) $teamId, $payload);
        $observation->setAttribute('source_score_15m', $this->observations->sourceScore(
            teamId: (int) $teamId,
            sourceHash: $observation->source_hash,
            windowMinutes: 15,
        ));
        $observation->makeHidden(['id', 'team_id', 'source_ip']);

        return response()->json(serializeApiResponse($observation))->setStatusCode(201);
    }
}
