<?php

namespace App\Services\Realm;

/**
 * Versioned semantic schema for Realm Operations Evidence Graphs.
 *
 * The schema defines the allowed language of graph explanations.
 * It does not validate Realm truth or decide actions.
 *
 * See ADR-0012.
 */
class EvidenceGraphSchema
{
    public const SCHEMA_REF = 'EvidenceGraphSchema:v0.3';

    public const EDGE_KIND_LINEAGE = 'lineage';

    public const EDGE_KIND_EXPLANATION = 'explanation';

    /**
     * @return array<string, mixed>
     */
    public static function v03(): array
    {
        return [
            'schema_ref' => self::SCHEMA_REF,
            'node_kinds' => [
                'Artifact',
                'ProtocolIdentity',
                'RuntimeObservation',
                'MeshConvergenceEvidence',
                'ComparisonContract',
                'VerificationReport',
                'SemanticHealth',
                'ProcessHealth',
            ],
            'conclusion_node_kinds' => [
                'MeshConvergenceEvidence',
                'SemanticHealth',
            ],
            'allowed_relations_by_edge_kind' => [
                self::EDGE_KIND_LINEAGE => [
                    'produced_by',
                    'resulted_in',
                    'anchored_by',
                    'provides_input_for',
                    'observed_from',
                ],
                self::EDGE_KIND_EXPLANATION => [
                    'evaluated_by',
                    'supports',
                    'blocked_by',
                    'compared_against',
                    'derived_from',
                ],
            ],
            'forbidden_relations' => [
                'grants_authority',
                'repairs',
                'elects',
                'promotes',
                'synchronizes',
                'decides',
            ],
            'authority_bearing_node_kinds' => [
                'Artifact',
                'ProtocolIdentity',
                'RuntimeObservation',
                'MeshConvergenceEvidence',
                'ComparisonContract',
                'VerificationReport',
                'SemanticHealth',
                'ProcessHealth',
            ],
        ];
    }
}
