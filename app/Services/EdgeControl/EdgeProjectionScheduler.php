<?php

namespace App\Services\EdgeControl;

use App\Models\ControlPlanePeer;
use App\Models\EdgeProjection;

class EdgeProjectionScheduler
{
    public function readiness(EdgeProjection $projection): array
    {
        $required = collect($projection->required_capabilities ?: [])
            ->filter()
            ->values();

        if ($required->isEmpty()) {
            return [
                'schedulable' => true,
                'required_capabilities' => [],
                'matching_peers' => [],
                'capability_snapshot_hash' => null,
                'capability_snapshot_version' => 1,
                'reason' => 'no_node_capability_required',
            ];
        }

        $peers = ControlPlanePeer::query()
            ->where('team_id', $projection->team_id)
            ->where('status', ControlPlanePeer::STATUS_ONLINE)
            ->get()
            ->filter(fn (ControlPlanePeer $peer): bool => $peer->providesAllCapabilities($required->all()))
            ->values();

        if ($peers->isEmpty()) {
            return [
                'schedulable' => false,
                'required_capabilities' => $required->all(),
                'matching_peers' => [],
                'capability_snapshot_hash' => null,
                'capability_snapshot_version' => 1,
                'reason' => 'no_node_provides_required_capabilities',
            ];
        }

        $capabilitySnapshot = $peers
            ->map(fn (ControlPlanePeer $peer): array => [
                'uuid' => $peer->uuid,
                'capability_hash' => $peer->capabilitySnapshotHash(),
                'capability_version' => $peer->capabilitySnapshotVersion(),
            ])
            ->values()
            ->all();

        return [
            'schedulable' => true,
            'required_capabilities' => $required->all(),
            'matching_peers' => $peers
                ->map(fn (ControlPlanePeer $peer): array => [
                    'uuid' => $peer->uuid,
                    'name' => $peer->name,
                    'role' => $peer->role,
                    'region' => $peer->region,
                    'capabilities' => $peer->capabilities ?: [],
                ])
                ->all(),
            'capability_snapshot_hash' => hash('sha256', json_encode($capabilitySnapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'capability_snapshot_version' => max($peers->map(fn (ControlPlanePeer $peer): int => $peer->capabilitySnapshotVersion())->all()),
            'reason' => 'capable_node_available',
        ];
    }

    public function markNotScheduledIfMissingCapabilities(EdgeProjection $projection): EdgeProjection
    {
        $readiness = $this->readiness($projection);
        if (! $readiness['schedulable']) {
            $projection->update([
                'status' => EdgeProjection::STATUS_NOT_SCHEDULED,
                'capability_snapshot_hash' => $readiness['capability_snapshot_hash'],
                'capability_snapshot_version' => $readiness['capability_snapshot_version'],
                'metadata' => array_merge((array) $projection->metadata, [
                    'schedule_reason' => $readiness['reason'],
                    'missing_capabilities' => $readiness['required_capabilities'],
                ]),
            ]);
        } else {
            $projection->update([
                'capability_snapshot_hash' => $readiness['capability_snapshot_hash'],
                'capability_snapshot_version' => $readiness['capability_snapshot_version'],
                'metadata' => array_merge((array) $projection->metadata, [
                    'schedule_reason' => $readiness['reason'],
                    'matching_peers' => $readiness['matching_peers'],
                ]),
            ]);
        }

        return $projection->refresh();
    }
}
