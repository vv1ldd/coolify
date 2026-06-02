<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ControlPlanePeer;
use App\Services\ControlPlane\ControlPlaneSyncService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ControlPlaneSyncController extends Controller
{
    public function __construct(private readonly ControlPlaneSyncService $sync) {}

    public function index(Request $request)
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }

        $validator = Validator::make($request->query(), [
            'status' => ['nullable', 'string', Rule::in([
                ControlPlanePeer::STATUS_PENDING,
                ControlPlanePeer::STATUS_ONLINE,
                ControlPlanePeer::STATUS_STALE,
                ControlPlanePeer::STATUS_INVALID,
            ])],
            'region' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $peers = ControlPlanePeer::query()
            ->where('team_id', (int) $teamId)
            ->when($request->query('status'), fn ($query, string $status) => $query->where('status', $status))
            ->when($request->query('region'), fn ($query, string $region) => $query->where('region', $region))
            ->withCount('snapshots')
            ->latest('last_seen_at')
            ->limit((int) $request->integer('limit', 100))
            ->get()
            ->map(fn (ControlPlanePeer $peer): array => $this->sync->publicPeerStatus($peer))
            ->values();

        return response()->json($peers);
    }

    public function snapshot(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'peer_uuid' => ['nullable', 'string', 'max:128'],
            'name' => ['nullable', 'string', 'max:255'],
            'endpoint_url' => ['nullable', 'url', 'max:2048'],
            'public_ip' => ['nullable', 'ip'],
            'region' => ['nullable', 'string', 'max:64', 'regex:/^[a-zA-Z0-9._:-]+$/'],
            'role' => ['nullable', 'string', Rule::in([
                ControlPlanePeer::ROLE_OBSERVER,
                ControlPlanePeer::ROLE_CONTROL_PLANE,
                ControlPlanePeer::ROLE_EDGE_AGENT,
            ])],
            'capabilities' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'snapshot' => ['nullable', 'array'],
            'observed_at' => ['nullable', 'date'],
            'servers_summary' => ['nullable', 'array'],
            'servers' => ['nullable', 'array'],
            'dns_steering_readiness' => ['nullable', 'array'],
            'edge_policy_versions' => ['nullable', 'array'],
            'regional_readiness_summary' => ['nullable', 'array'],
            'regional_readiness' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $result = $this->sync->ingestSignedSnapshot($request, $validator->validated());
        if (! $result['ok']) {
            return response()->json(['message' => $result['message']], $result['status']);
        }

        return response()->json([
            'message' => 'ok',
            'peer' => $this->sync->publicPeerStatus($result['peer']),
            'snapshot_uuid' => $result['snapshot']->uuid,
        ])->setStatusCode(201);
    }
}
