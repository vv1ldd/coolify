<?php

namespace App\Services\Realm;

/**
 * Validates Evidence Graph expression structure against a versioned schema.
 *
 * Graph validation checks whether an explanation is structurally honest.
 * It does not validate Realm correctness and must never repair the graph.
 *
 * See ADR-0012.
 */
class EvidenceGraphValidator
{
    public const VALIDATOR_VERSION = 'EvidenceGraphValidator:v0.3';

    /**
     * @param  array<string, mixed>  $graph
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public function validate(array $graph, array $schema): array
    {
        $errors = [];
        $warnings = [];
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $schemaRef = (string) ($schema['schema_ref'] ?? EvidenceGraphSchema::SCHEMA_REF);

        if (($graph['schema_ref'] ?? null) !== $schemaRef) {
            $errors[] = $this->error(
                'MISSING_OR_MISMATCHED_SCHEMA_REF',
                'EvidenceGraph must declare the schema_ref used to interpret its relations.',
                ['expected' => $schemaRef, 'actual' => $graph['schema_ref'] ?? null],
            );
        }

        $nodeIds = [];
        $nodeKinds = $this->stringList($schema['node_kinds'] ?? []);
        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            $nodeKind = (string) ($node['kind'] ?? '');
            if ($nodeId === '') {
                $errors[] = $this->error('MISSING_NODE_ID', 'Every evidence graph node must declare an id.', ['node' => $node]);
                continue;
            }

            $nodeIds[$nodeId] = $node;
            if (! in_array($nodeKind, $nodeKinds, true)) {
                $errors[] = $this->error('UNKNOWN_NODE_KIND', 'Node kind is not allowed by schema.', [
                    'node_id' => $nodeId,
                    'kind' => $nodeKind,
                    'schema_ref' => $schemaRef,
                ]);
            }
        }

        $this->validateEdges($edges, $schema, $schemaRef, $nodeIds, $errors);
        $this->validateConclusionPaths($nodes, $edges, $schema, $errors);
        $this->validateAuthorityDeclarations($nodes, $schema, $errors);

        return [
            'valid' => $errors === [],
            'schema_ref' => $schemaRef,
            'errors' => $errors,
            'warnings' => $warnings,
            'checked_nodes' => count($nodes),
            'checked_edges' => count($edges),
            'validator_version' => self::VALIDATOR_VERSION,
            'validated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array<int, mixed>  $edges
     * @param  array<string, mixed>  $schema
     * @param  array<string, array<string, mixed>>  $nodeIds
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function validateEdges(array $edges, array $schema, string $schemaRef, array $nodeIds, array &$errors): void
    {
        $allowedByKind = is_array($schema['allowed_relations_by_edge_kind'] ?? null)
            ? $schema['allowed_relations_by_edge_kind']
            : [];
        $forbidden = $this->stringList($schema['forbidden_relations'] ?? []);

        foreach ($edges as $edge) {
            $from = (string) ($edge['from'] ?? '');
            $to = (string) ($edge['to'] ?? '');
            $edgeKind = (string) ($edge['edge_kind'] ?? '');
            $relation = (string) ($edge['relation'] ?? '');

            if (! isset($nodeIds[$from])) {
                $errors[] = $this->error('UNKNOWN_EDGE_FROM_NODE', 'Edge source node does not exist.', compact('from', 'to', 'relation'));
            }

            if (! isset($nodeIds[$to])) {
                $errors[] = $this->error('UNKNOWN_EDGE_TO_NODE', 'Edge target node does not exist.', compact('from', 'to', 'relation'));
            }

            if (in_array($relation, $forbidden, true)) {
                $errors[] = $this->error('FORBIDDEN_EDGE_RELATION', 'Edge relation would imply control-plane authority.', [
                    'from' => $from,
                    'to' => $to,
                    'relation' => $relation,
                ]);
            }

            $allowedRelations = $this->stringList($allowedByKind[$edgeKind] ?? []);
            if ($edgeKind === '' || $allowedRelations === [] || ! in_array($relation, $allowedRelations, true)) {
                $errors[] = $this->error('INVALID_EDGE_SEMANTICS', 'Edge relation is not allowed for edge_kind under schema.', [
                    'from' => $from,
                    'to' => $to,
                    'edge_kind' => $edgeKind,
                    'relation' => $relation,
                    'schema_ref' => $schemaRef,
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<int, mixed>  $edges
     * @param  array<string, mixed>  $schema
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function validateConclusionPaths(array $nodes, array $edges, array $schema, array &$errors): void
    {
        $conclusionKinds = $this->stringList($schema['conclusion_node_kinds'] ?? []);
        $derivationRelations = ['derived_from', 'supports', 'blocked_by', 'compared_against', 'evaluated_by'];

        foreach ($nodes as $node) {
            $nodeId = (string) ($node['id'] ?? '');
            $nodeKind = (string) ($node['kind'] ?? '');
            if (! in_array($nodeKind, $conclusionKinds, true)) {
                continue;
            }

            $hasPath = collect($edges)->contains(function (array $edge) use ($nodeId, $derivationRelations): bool {
                return (($edge['from'] ?? null) === $nodeId || ($edge['to'] ?? null) === $nodeId)
                    && in_array((string) ($edge['relation'] ?? ''), $derivationRelations, true);
            });

            if (! $hasPath) {
                $errors[] = $this->error('MISSING_DERIVATION_PATH', 'Conclusion node must expose an explicit derivation or blocking path.', [
                    'node_id' => $nodeId,
                    'kind' => $nodeKind,
                ]);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $nodes
     * @param  array<string, mixed>  $schema
     * @param  array<int, array<string, mixed>>  $errors
     */
    private function validateAuthorityDeclarations(array $nodes, array $schema, array &$errors): void
    {
        $authorityKinds = $this->stringList($schema['authority_bearing_node_kinds'] ?? []);

        foreach ($nodes as $node) {
            $nodeKind = (string) ($node['kind'] ?? '');
            if (! in_array($nodeKind, $authorityKinds, true)) {
                continue;
            }

            $authority = (string) ($node['authority'] ?? '');
            if ($authority === '' || strtoupper($authority) === RealmOperationsService::STATUS_UNKNOWN) {
                $errors[] = $this->error('MISSING_AUTHORITY_DECLARATION', 'Authority-bearing node must declare its authority domain.', [
                    'node_id' => $node['id'] ?? null,
                    'kind' => $nodeKind,
                ]);
            }
        }
    }

    /**
     * @param  mixed  $values
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_map('strval', $values));
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function error(string $code, string $reason, array $details = []): array
    {
        return [
            'code' => $code,
            'reason' => $reason,
            'details' => $details,
        ];
    }
}
