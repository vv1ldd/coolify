<?php

namespace App\Services\Realm;

/**
 * Builds a navigable Evidence Graph from existing Realm Operations projections.
 *
 * Nodes describe facts. Edges describe relationships. Edges are authoritative;
 * derived_from on nodes is a navigation index only.
 *
 * See ADR-0012.
 */
class EvidenceGraphProjection
{
    /** @var array<int, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<int, array<string, mixed>> */
    private array $edges = [];

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{nodes: array<int, array<string, mixed>>, edges: array<int, array<string, mixed>>}
     */
    public function project(array $snapshot): array
    {
        $this->nodes = [];
        $this->edges = [];

        $this->addArtifactNode($snapshot['artifact'] ?? []);
        $this->addProtocolNode($snapshot['protocol'] ?? []);
        $this->addRuntimeObservationNodes($snapshot['runtime_observations'] ?? []);
        $this->addMeshConvergenceNodes($snapshot['mesh_convergence'] ?? []);
        $this->addVerificationNodes($snapshot['verification'] ?? [], $snapshot['runtime'] ?? []);
        $this->addProcessHealthNode($snapshot['process_health'] ?? []);

        return [
            'nodes' => $this->withDerivedFromIndex($this->nodes),
            'edges' => $this->edges,
        ];
    }

    /**
     * @param  array<string, mixed>  $artifact
     */
    private function addArtifactNode(array $artifact): void
    {
        $this->addNode([
            'id' => 'artifact',
            'kind' => 'Artifact',
            'value' => $artifact['image_digest'] ?? RealmOperationsService::STATUS_UNKNOWN,
            'trust_state' => $this->trustStateFromValue($artifact['image_digest'] ?? null),
            'authority' => 'Deployment',
            'source' => (string) ($artifact['source'] ?? 'Console'),
            'evidence_ref' => 'artifact:deployment-image',
            'observed_at' => null,
            'details' => [
                'image_ref' => $artifact['image_ref'] ?? RealmOperationsService::STATUS_UNKNOWN,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $protocol
     */
    private function addProtocolNode(array $protocol): void
    {
        $this->addNode([
            'id' => 'protocol-identity',
            'kind' => 'ProtocolIdentity',
            'value' => $protocol['package_fingerprint'] ?? RealmOperationsService::STATUS_UNKNOWN,
            'trust_state' => $this->trustStateFromValue($protocol['package_fingerprint'] ?? null),
            'authority' => 'Protocol',
            'source' => (string) ($protocol['source'] ?? 'Protocol'),
            'evidence_ref' => 'protocol:package-fingerprint',
            'observed_at' => null,
            'details' => [
                'distribution_digest' => $protocol['distribution_digest'] ?? RealmOperationsService::STATUS_UNKNOWN,
                'protocol_version' => $protocol['protocol_version'] ?? RealmOperationsService::STATUS_UNKNOWN,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $observations
     */
    private function addRuntimeObservationNodes(array $observations): void
    {
        foreach ($observations as $observation) {
            $nodeId = (string) ($observation['node_id'] ?? 'unknown');
            $graphNodeId = 'runtime-observation:'.$nodeId;

            $this->addNode([
                'id' => $graphNodeId,
                'kind' => 'RuntimeObservation',
                'value' => $observation['state_root'] ?? RealmOperationsService::STATUS_UNKNOWN,
                'trust_state' => ($observation['reachable'] ?? false) ? RealmOperationsService::STATUS_OK : RealmOperationsService::STATUS_UNKNOWN,
                'authority' => (string) ($observation['authority'] ?? 'Runtime'),
                'source' => (string) ($observation['source'] ?? 'Runtime Endpoint'),
                'evidence_ref' => (string) ($observation['evidence_ref'] ?? $graphNodeId),
                'observed_at' => $observation['observed_at'] ?? null,
                'details' => [
                    'node_id' => $nodeId,
                    'history_head_kind' => $observation['history_head_kind'] ?? RealmOperationsService::STATUS_UNKNOWN,
                    'history_head' => $observation['history_head'] ?? RealmOperationsService::STATUS_UNKNOWN,
                    'state_root' => $observation['state_root'] ?? RealmOperationsService::STATUS_UNKNOWN,
                    'event_count' => $observation['event_count'] ?? null,
                    'reachable' => (bool) ($observation['reachable'] ?? false),
                ],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meshConvergence
     */
    private function addMeshConvergenceNodes(array $meshConvergence): void
    {
        if ($meshConvergence === []) {
            return;
        }

        $meshNodeId = 'mesh-convergence-evidence';
        $contractRef = (string) ($meshConvergence['comparison_contract_ref'] ?? MeshConvergenceEvidenceProjection::COMPARISON_CONTRACT_REF);
        $evidenceRef = (string) ($meshConvergence['evidence_ref'] ?? $meshNodeId);

        $this->addNode([
            'id' => $meshNodeId,
            'kind' => 'MeshConvergenceEvidence',
            'value' => $meshConvergence['result'] ?? RealmOperationsService::STATUS_UNKNOWN,
            'trust_state' => $this->meshTrustState($meshConvergence['result'] ?? null),
            'authority' => (string) ($meshConvergence['authority'] ?? MeshConvergenceEvidenceProjection::AUTHORITY_OPERATIONS_PROJECTION),
            'source' => (string) ($meshConvergence['source'] ?? MeshConvergenceEvidenceProjection::SOURCE_RUNTIME_OBSERVATION_COMPARISON),
            'evidence_ref' => $evidenceRef,
            'observed_at' => $meshConvergence['observed_at'] ?? null,
            'details' => [
                'scope' => $meshConvergence['scope'] ?? MeshConvergenceEvidenceProjection::SCOPE_RUNTIME_OBSERVATION_EQUIVALENCE,
                'reason' => $meshConvergence['reason'] ?? null,
                'reason_code' => $meshConvergence['reason_code'] ?? null,
            ],
        ]);

        $this->addNode([
            'id' => $contractRef,
            'kind' => 'ComparisonContract',
            'value' => $contractRef,
            'trust_state' => RealmOperationsService::STATUS_OK,
            'authority' => MeshConvergenceEvidenceProjection::AUTHORITY_OPERATIONS_PROJECTION,
            'source' => $contractRef,
            'evidence_ref' => $contractRef,
            'observed_at' => null,
            'details' => $meshConvergence['comparison_contract'] ?? MeshConvergenceEvidenceProjection::comparisonContract(),
        ]);

        foreach ($meshConvergence['observations'] ?? [] as $observation) {
            $nodeId = (string) ($observation['node_id'] ?? 'unknown');
            $from = 'runtime-observation:'.$nodeId;

            $this->addEdge([
                'from' => $from,
                'to' => $meshNodeId,
                'relation' => 'compared_against',
                'reason' => 'Runtime observation participates in mesh convergence comparison under '.$contractRef.'.',
                'evidence_ref' => $evidenceRef,
            ]);
        }

        $this->addEdge([
            'from' => $meshNodeId,
            'to' => $contractRef,
            'relation' => 'evaluated_by',
            'reason' => 'Mesh convergence result produced under explicit runtime comparison contract.',
            'evidence_ref' => $contractRef,
        ]);
    }

    /**
     * @param  array<string, mixed>  $verification
     * @param  array<string, mixed>  $runtime
     */
    private function addVerificationNodes(array $verification, array $runtime): void
    {
        $verificationNodeId = 'verification-report';
        $semanticNodeId = 'semantic-health';
        $reachable = (bool) ($verification['reachable'] ?? false);
        $semanticHealth = (string) ($verification['semantic_health'] ?? RealmOperationsService::STATUS_UNKNOWN);
        $primaryRuntimeNodeId = $this->firstReachableRuntimeObservationNodeId();

        $this->addNode([
            'id' => $verificationNodeId,
            'kind' => 'VerificationReport',
            'value' => $verification['shadow_verify'] ?? RealmOperationsService::STATUS_UNKNOWN,
            'trust_state' => (string) ($verification['shadow_verify'] ?? RealmOperationsService::STATUS_UNKNOWN),
            'authority' => 'Verifier',
            'source' => (string) ($verification['source'] ?? 'Verifier'),
            'evidence_ref' => 'verification-report:shadow',
            'observed_at' => $verification['checked_at'] ?? null,
            'details' => [
                'verifier' => $verification['verifier'] ?? RealmOperationsService::STATUS_UNKNOWN,
                'reason' => $verification['reason'] ?? null,
                'observed_state_root' => $verification['observed_state_root'] ?? null,
                'observed_history_head' => $verification['observed_history_head'] ?? null,
                'raw_event_count' => $verification['raw_event_count'] ?? null,
                'canonical_event_count' => $verification['canonical_event_count'] ?? null,
                'reachable' => $reachable,
            ],
        ]);

        $this->addNode([
            'id' => $semanticNodeId,
            'kind' => 'SemanticHealth',
            'value' => $semanticHealth,
            'trust_state' => $semanticHealth,
            'authority' => 'Verifier',
            'source' => 'OperationsProjection',
            'evidence_ref' => 'semantic-health:projection',
            'observed_at' => $verification['checked_at'] ?? null,
            'details' => [
                'conformance' => $verification['conformance'] ?? RealmOperationsService::STATUS_UNKNOWN,
            ],
        ]);

        if ($primaryRuntimeNodeId !== null && ($runtime['reachable'] ?? false) === true) {
            $this->addEdge([
                'from' => $primaryRuntimeNodeId,
                'to' => $verificationNodeId,
                'relation' => 'observed_from',
                'reason' => 'Shadow verification consumes runtime observation as replay input.',
                'evidence_ref' => 'verification-report:shadow',
            ]);
        }

        if ($reachable && $semanticHealth === RealmOperationsService::STATUS_OK) {
            $this->addEdge([
                'from' => $verificationNodeId,
                'to' => $semanticNodeId,
                'relation' => 'derived_from',
                'reason' => 'Semantic health is projected from verifier report evidence.',
                'evidence_ref' => 'semantic-health:projection',
            ]);
        } else {
            $blockedReason = $reachable
                ? (string) ($verification['reason'] ?? 'Verifier report does not support semantic health conclusion.')
                : 'Verifier endpoint unreachable; no verification report available.';

            $this->addEdge([
                'from' => $semanticNodeId,
                'to' => $verificationNodeId,
                'relation' => 'blocked_by',
                'reason' => $blockedReason,
                'evidence_ref' => 'semantic-health:projection',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $processHealth
     */
    private function addProcessHealthNode(array $processHealth): void
    {
        $this->addNode([
            'id' => 'process-health',
            'kind' => 'ProcessHealth',
            'value' => $processHealth['status'] ?? RealmOperationsService::STATUS_UNKNOWN,
            'trust_state' => (string) ($processHealth['status'] ?? RealmOperationsService::STATUS_UNKNOWN),
            'authority' => 'Runtime',
            'source' => (string) ($processHealth['source'] ?? 'Runtime'),
            'evidence_ref' => 'process-health:node-observation',
            'observed_at' => $processHealth['observed_at'] ?? null,
            'details' => [
                'note' => $processHealth['note'] ?? null,
                'target_node' => $processHealth['target_node'] ?? null,
            ],
        ]);
    }

    private function firstReachableRuntimeObservationNodeId(): ?string
    {
        foreach ($this->nodes as $node) {
            if (($node['kind'] ?? null) === 'RuntimeObservation' && ($node['details']['reachable'] ?? false) === true) {
                return (string) ($node['id'] ?? null);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function addNode(array $node): void
    {
        $this->nodes[] = array_merge(['derived_from' => []], $node);
    }

    /**
     * @param  array<string, mixed>  $edge
     */
    private function addEdge(array $edge): void
    {
        $this->edges[] = $edge;
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @return array<int, array<string, mixed>>
     */
    private function withDerivedFromIndex(array $nodes): array
    {
        $incomingRelations = [
            'derived_from',
            'compared_against',
            'evaluated_by',
            'observed_from',
            'produced_by',
        ];

        $indexByTarget = [];
        foreach ($this->edges as $edge) {
            $relation = (string) ($edge['relation'] ?? '');
            if (! in_array($relation, $incomingRelations, true)) {
                continue;
            }

            $to = (string) ($edge['to'] ?? '');
            $from = (string) ($edge['from'] ?? '');
            if ($to === '' || $from === '') {
                continue;
            }

            $indexByTarget[$to][] = $from;
        }

        return array_map(function (array $node) use ($indexByTarget): array {
            $nodeId = (string) ($node['id'] ?? '');
            $node['derived_from'] = array_values(array_unique($indexByTarget[$nodeId] ?? []));

            return $node;
        }, $nodes);
    }

    private function trustStateFromValue(mixed $value): string
    {
        if ($value === null || $value === '' || strtoupper((string) $value) === RealmOperationsService::STATUS_UNKNOWN) {
            return RealmOperationsService::STATUS_UNKNOWN;
        }

        return RealmOperationsService::STATUS_OK;
    }

    private function meshTrustState(mixed $result): string
    {
        return match (strtoupper((string) $result)) {
            MeshConvergenceEvidenceProjection::RESULT_CONVERGED => RealmOperationsService::STATUS_OK,
            MeshConvergenceEvidenceProjection::RESULT_DIVERGED => RealmOperationsService::STATUS_FAIL,
            default => RealmOperationsService::STATUS_UNKNOWN,
        };
    }
}
