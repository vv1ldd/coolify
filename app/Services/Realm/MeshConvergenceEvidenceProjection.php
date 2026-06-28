<?php

namespace App\Services\Realm;

/**
 * Projects Mesh Convergence Evidence from independent runtime observations.
 *
 * This is derived evidence under an explicit comparison contract.
 * It does not coordinate nodes, elect leaders, or certify remote reality.
 *
 * See ADR-0012.
 */
class MeshConvergenceEvidenceProjection
{
    public const RESULT_CONVERGED = 'CONVERGED';

    public const RESULT_DIVERGED = 'DIVERGED';

    public const RESULT_UNKNOWN = 'UNKNOWN';

    public const SCOPE_RUNTIME_OBSERVATION_EQUIVALENCE = 'runtime_observation_equivalence';

    public const COMPARISON_CONTRACT_REF = 'RuntimeComparisonContract:v0.1';

    public const AUTHORITY_OPERATIONS_PROJECTION = 'OperationsProjection';

    public const SOURCE_RUNTIME_OBSERVATION_COMPARISON = 'RuntimeObservationComparison';

    /**
     * @return array<string, mixed>
     */
    public static function comparisonContract(): array
    {
        return [
            'id' => self::COMPARISON_CONTRACT_REF,
            'comparable_fields' => [
                'history_head_kind',
                'history_head',
                'state_root',
                'event_count',
            ],
            'rules' => [
                'all_equal' => self::RESULT_CONVERGED,
                'comparable_contradiction' => self::RESULT_DIVERGED,
                'insufficient_evidence' => self::RESULT_UNKNOWN,
            ],
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    public function project(array $observations): array
    {
        $observedAt = now()->toIso8601String();
        $reachableObservations = array_values(array_filter(
            $observations,
            fn (array $observation): bool => (bool) ($observation['reachable'] ?? false)
        ));

        if (count($reachableObservations) < 2) {
            return $this->unknownEvidence(
                observations: $observations,
                observedAt: $observedAt,
                reason: 'At least two independent runtime observations are required for mesh convergence comparison.',
                reasonCode: 'MISSING_RUNTIME_OBSERVATION',
            );
        }

        $comparable = [];
        foreach ($reachableObservations as $observation) {
            $fields = $this->comparableFields($observation);
            if ($fields === null) {
                return $this->unknownEvidence(
                    observations: $observations,
                    observedAt: $observedAt,
                    reason: 'One or more runtime observations are missing comparable fields required by '.self::COMPARISON_CONTRACT_REF.'.',
                    reasonCode: 'MISSING_COMPARABLE_FIELDS',
                );
            }

            $comparable[] = [
                'node_id' => (string) ($observation['node_id'] ?? 'unknown'),
                'fields' => $fields,
            ];
        }

        $kinds = array_unique(array_column(array_column($comparable, 'fields'), 'history_head_kind'));
        if (count($kinds) !== 1) {
            return $this->unknownEvidence(
                observations: $observations,
                observedAt: $observedAt,
                reason: 'Runtime observations use different history_head_kind values, so they cannot be compared under '.self::COMPARISON_CONTRACT_REF.'.',
                reasonCode: 'UNSUPPORTED_COMPARISON_CONTRACT',
            );
        }

        $eventCounts = array_unique(array_column(array_column($comparable, 'fields'), 'event_count'));
        if (count($eventCounts) !== 1) {
            return $this->unknownEvidence(
                observations: $observations,
                observedAt: $observedAt,
                reason: 'Runtime observations report different event_count values without ancestry proof, so lagging cannot be inferred and comparison remains inconclusive under '.self::COMPARISON_CONTRACT_REF.'.',
                reasonCode: 'INSUFFICIENT_LINEAGE_FOR_COMPARISON',
            );
        }

        $reference = $comparable[0]['fields'];
        foreach (array_slice($comparable, 1) as $candidate) {
            if ($candidate['fields'] === $reference) {
                continue;
            }

            return $this->derivedEvidence(
                observations: $observations,
                observedAt: $observedAt,
                result: self::RESULT_DIVERGED,
                reason: 'Comparable runtime observations satisfy '.self::COMPARISON_CONTRACT_REF.' but report different history_head or state_root values.',
                reasonCode: 'COMPARABLE_OBSERVATIONS_CONTRADICT',
            );
        }

        return $this->derivedEvidence(
            observations: $observations,
            observedAt: $observedAt,
            result: self::RESULT_CONVERGED,
            reason: 'Both observations satisfy the same runtime comparison contract: history_head_kind, history_head, state_root, and event_count match.',
            reasonCode: 'INDEPENDENT_OBSERVATIONS_MATCH',
        );
    }

    /**
     * @param  array<string, mixed>  $observation
     * @return array<string, mixed>|null
     */
    private function comparableFields(array $observation): ?array
    {
        $historyHeadKind = $this->comparableValue($observation['history_head_kind'] ?? null);
        $historyHead = $this->comparableValue($observation['history_head'] ?? null);
        $stateRoot = $this->comparableValue($observation['state_root'] ?? null);
        $eventCount = $observation['event_count'] ?? null;

        if ($historyHeadKind === null || $historyHead === null || $stateRoot === null || ! is_int($eventCount)) {
            return null;
        }

        return [
            'history_head_kind' => $historyHeadKind,
            'history_head' => $historyHead,
            'state_root' => $stateRoot,
            'event_count' => $eventCount,
        ];
    }

    private function comparableValue(mixed $value): ?string
    {
        if ($value === null || $value === '' || strtoupper((string) $value) === RealmOperationsService::STATUS_UNKNOWN) {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private function derivedEvidence(
        array $observations,
        string $observedAt,
        string $result,
        string $reason,
        string $reasonCode,
    ): array {
        return $this->evidenceArtifact(
            observations: $observations,
            observedAt: $observedAt,
            result: $result,
            reason: $reason,
            reasonCode: $reasonCode,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private function unknownEvidence(
        array $observations,
        string $observedAt,
        string $reason,
        string $reasonCode,
    ): array {
        return $this->evidenceArtifact(
            observations: $observations,
            observedAt: $observedAt,
            result: self::RESULT_UNKNOWN,
            reason: $reason,
            reasonCode: $reasonCode,
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private function evidenceArtifact(
        array $observations,
        string $observedAt,
        string $result,
        string $reason,
        string $reasonCode,
    ): array {
        // evidence_ref must be content-derived, not time-derived, so that the
        // same evidence set yields the same identity (Law of Independent Projection).
        $fingerprint = collect($observations)
            ->map(fn (array $observation): string => implode(':', [
                (string) ($observation['node_id'] ?? 'unknown'),
                (string) ($observation['history_head_kind'] ?? ''),
                (string) ($observation['history_head'] ?? ''),
                (string) ($observation['state_root'] ?? ''),
                (string) ($observation['event_count'] ?? ''),
                ($observation['reachable'] ?? false) ? '1' : '0',
            ]))
            ->sort()
            ->values()
            ->implode('|');

        $evidenceRef = 'runtime-observation-set:'.sha1($fingerprint);

        return [
            'id' => 'mesh-convergence:'.sha1($evidenceRef.':'.$result),
            'scope' => self::SCOPE_RUNTIME_OBSERVATION_EQUIVALENCE,
            'observations' => $observations,
            'comparison_contract_ref' => self::COMPARISON_CONTRACT_REF,
            'comparison_contract' => self::comparisonContract(),
            'result' => $result,
            'reason' => $reason,
            'reason_code' => $reasonCode,
            'authority' => self::AUTHORITY_OPERATIONS_PROJECTION,
            'source' => self::SOURCE_RUNTIME_OBSERVATION_COMPARISON,
            'evidence_ref' => $evidenceRef,
            'observed_at' => $observedAt,
            'graph' => $this->graph($observations, $evidenceRef),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     * @return array<string, mixed>
     */
    private function graph(array $observations, string $evidenceRef): array
    {
        $nodes = [];
        $edges = [];

        foreach ($observations as $observation) {
            $nodeId = (string) ($observation['node_id'] ?? 'unknown');
            $observationId = 'runtime-observation:'.$nodeId;

            $nodes[] = [
                'id' => $observationId,
                'kind' => 'RuntimeObservation',
                'node_id' => $nodeId,
                'authority' => 'Runtime',
                'source' => 'Runtime Endpoint',
                'evidence_ref' => $observation['evidence_ref'] ?? $observationId,
            ];

            $edges[] = [
                'from' => $observationId,
                'to' => 'mesh-convergence-evidence',
                'relation' => 'compared_against',
                'reason' => 'Observation participates in runtime observation comparison.',
                'evidence_ref' => $evidenceRef,
            ];
        }

        $nodes[] = [
            'id' => 'mesh-convergence-evidence',
            'kind' => 'MeshConvergenceEvidence',
            'authority' => self::AUTHORITY_OPERATIONS_PROJECTION,
            'source' => self::SOURCE_RUNTIME_OBSERVATION_COMPARISON,
            'evidence_ref' => $evidenceRef,
        ];

        $nodes[] = [
            'id' => self::COMPARISON_CONTRACT_REF,
            'kind' => 'ComparisonContract',
            'authority' => self::AUTHORITY_OPERATIONS_PROJECTION,
            'source' => self::COMPARISON_CONTRACT_REF,
            'evidence_ref' => self::COMPARISON_CONTRACT_REF,
        ];

        $edges[] = [
            'from' => 'mesh-convergence-evidence',
            'to' => self::COMPARISON_CONTRACT_REF,
            'relation' => 'evaluated_by',
            'reason' => 'Result produced under explicit runtime comparison contract.',
            'evidence_ref' => self::COMPARISON_CONTRACT_REF,
        ];

        return [
            'nodes' => $nodes,
            'edges' => $edges,
        ];
    }
}
