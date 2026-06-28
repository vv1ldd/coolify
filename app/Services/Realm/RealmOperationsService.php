<?php

namespace App\Services\Realm;

use App\Models\SimpleL1EvidencePackage;
use App\Models\SimpleL1NodeObservation;
use Illuminate\Support\Facades\Http;

/**
 * Aggregates read-only Realm evidence into an Operations Console projection.
 *
 * This service is intentionally an Evidence Graph projection.
 * It must never infer protocol truth or substitute evidence.
 *
 * See ADR-0012.
 */
class RealmOperationsService
{
    public const STATUS_OK = 'OK';

    public const STATUS_UNKNOWN = 'UNKNOWN';

    public const STATUS_FAIL = 'FAIL';

    public const VERIFICATION_RESULT_SUPPORTED = 'SUPPORTED';

    public const VERIFICATION_RESULT_BLOCKED = 'BLOCKED';

    public const VERIFICATION_RESULT_UNKNOWN = 'UNKNOWN';

    public const VERIFIER_SEMANTIC_BOUNDARY_CONTRACT_REF = 'VerifierSemanticBoundary:v0.1';

    public function __construct(
        private readonly MeshConvergenceEvidenceProjection $meshConvergence = new MeshConvergenceEvidenceProjection,
        private readonly EvidenceGraphProjection $evidenceGraph = new EvidenceGraphProjection,
        private readonly EvidenceGraphValidator $evidenceGraphValidator = new EvidenceGraphValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?int $teamId = null): array
    {
        $evidence = $this->latestEvidenceMetadata($teamId);
        $runtimeObservations = $this->probeRuntimeObservations();
        $runtimeProbe = $this->primaryRuntimeProbe($runtimeObservations);
        $verificationProbe = $this->probeShadowVerification();
        $processHealth = $this->latestProcessHealth($teamId);
        $meshConvergence = $this->meshConvergence->project($runtimeObservations);

        $snapshot = [
            'generated_at' => now()->toIso8601String(),
            'boundary' => [
                'rule' => 'Observe -> Aggregate -> Display',
                'note' => 'container healthy != semantic_health OK',
                'panel_role' => 'Console displays evidence; Protocol defines meaning.',
            ],
            'sources' => [
                ['role' => 'Protocol', 'responsibility' => 'defines meaning'],
                ['role' => 'Runtime', 'responsibility' => 'produces state'],
                ['role' => 'Verifier', 'responsibility' => 'proves equivalence'],
                ['role' => 'Console', 'responsibility' => 'displays evidence'],
            ],
            'artifact' => $this->artifactSection($evidence),
            'protocol' => $this->protocolSection($evidence),
            'runtime' => $this->runtimeSection($evidence, $runtimeProbe),
            'runtime_observations' => $runtimeObservations,
            'mesh_convergence' => $meshConvergence,
            'verification' => $this->verificationSection($evidence, $verificationProbe),
            'process_health' => $processHealth,
            'evidence_refs' => [
                'package_count' => $this->evidencePackageCount($teamId),
                'latest_package_sealed_at' => $this->latestEvidenceSealedAt($teamId),
                'runtime_checked_url' => $runtimeProbe['checked_url'] ?? null,
                'verifier_checked_url' => $verificationProbe['checked_url'] ?? null,
                'mesh_convergence_evidence_ref' => $meshConvergence['evidence_ref'] ?? null,
            ],
        ];

        $snapshot['evidence_graph'] = $this->evidenceGraph->project($snapshot);
        $snapshot['graph_validation'] = $this->evidenceGraphValidator->validate(
            $snapshot['evidence_graph'],
            EvidenceGraphSchema::v03(),
        );

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function artifactSection(array $evidence): array
    {
        return [
            'image_ref' => (string) config('sovereign.realm_operations.runtime_image', 'UNKNOWN'),
            'image_digest' => $this->stringOrUnknown($evidence['image_digest'] ?? null),
            'source' => 'Console',
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function protocolSection(array $evidence): array
    {
        return [
            'package_fingerprint' => $this->stringOrUnknown($evidence['package_fingerprint'] ?? null),
            'distribution_digest' => $this->stringOrUnknown($evidence['distribution_digest'] ?? null),
            'protocol_version' => $this->stringOrUnknown($evidence['protocol_version'] ?? 'realm-v1.0'),
            'source' => 'Protocol',
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $runtimeProbe
     * @return array<string, mixed>
     */
    private function runtimeSection(array $evidence, array $runtimeProbe): array
    {
        $remote = is_array($runtimeProbe['remote'] ?? null) ? $runtimeProbe['remote'] : [];
        $identityRealm = is_array($remote['identity_realm'] ?? null) ? $remote['identity_realm'] : [];

        return [
            'history_head' => $this->stringOrUnknown(
                $evidence['history_head']
                    ?? $remote['history_head']
                    ?? $identityRealm['history_head']
                    ?? null
            ),
            'history_head_kind' => $this->stringOrUnknown(
                $evidence['history_head_kind']
                    ?? $remote['history_head_kind']
                    ?? $identityRealm['history_head_kind']
                    ?? null
            ),
            'state_root' => $this->stringOrUnknown(
                $evidence['state_root']
                    ?? $identityRealm['state_root']
                    ?? null
            ),
            'last_transition' => $this->transitionLabel(
                $evidence['last_transition']
                    ?? $remote['last_transition']
                    ?? $identityRealm['last_transition']
                    ?? $evidence['last_event_type']
                    ?? null
            ),
            'event_count' => $identityRealm['event_count'] ?? $remote['event_count'] ?? null,
            'reachable' => (bool) ($runtimeProbe['reachable'] ?? false),
            'source' => 'Runtime',
        ];
    }

    /**
     * @param  array<string, mixed>  $evidence
     * @return array<string, mixed>
     */
    private function verificationSection(array $evidence, array $verificationProbe): array
    {
        $remote = is_array($verificationProbe['remote'] ?? null) ? $verificationProbe['remote'] : [];
        $rawStatus = $evidence['shadow_verify'] ?? $evidence['shadow_verification'] ?? $remote['status'] ?? null;
        $shadowVerify = $this->normalizeStatus($rawStatus);
        $reason = $remote['reason'] ?? null;
        $result = $this->classifyVerificationResult($verificationProbe, $shadowVerify, $rawStatus, $reason);

        return [
            'semantic_health' => $this->normalizeStatus($evidence['semantic_health'] ?? $remote['semantic_health'] ?? null),
            'shadow_verify' => $shadowVerify,
            'conformance' => $this->normalizeConformanceStatus($evidence['conformance'] ?? $evidence['certification_status'] ?? null),
            'result' => $result['result'],
            'result_contract_ref' => $result['result_contract_ref'],
            'result_reason_code' => $result['result_reason_code'],
            'verifier' => $this->stringOrUnknown($remote['verifier'] ?? null),
            'checked_at' => $this->stringOrUnknown($remote['checked_at'] ?? null),
            'reason' => $this->stringOrUnknown($reason),
            'observed_state_root' => $this->stringOrUnknown($remote['observed_state_root'] ?? null),
            'observed_history_head' => $this->stringOrUnknown($remote['observed_history_head'] ?? null),
            'raw_event_count' => $remote['raw_event_count'] ?? null,
            'canonical_event_count' => $remote['canonical_event_count'] ?? null,
            'reachable' => (bool) ($verificationProbe['reachable'] ?? false),
            'source' => 'Verifier',
        ];
    }

    /**
     * Classify verifier output under VerifierSemanticBoundary:v0.1.
     *
     * Projection-only: describes proof state, not policy or governance action.
     *
     * @return array{result: string, result_contract_ref: string, result_reason_code: string|null}
     */
    private function classifyVerificationResult(
        array $verificationProbe,
        string $shadowVerify,
        mixed $rawStatus,
        mixed $reason,
    ): array {
        $contractRef = self::VERIFIER_SEMANTIC_BOUNDARY_CONTRACT_REF;

        if (! ($verificationProbe['reachable'] ?? false)) {
            return [
                'result' => self::VERIFICATION_RESULT_UNKNOWN,
                'result_contract_ref' => $contractRef,
                'result_reason_code' => 'VERIFIER_UNREACHABLE',
            ];
        }

        if ($shadowVerify === self::STATUS_OK) {
            return [
                'result' => self::VERIFICATION_RESULT_SUPPORTED,
                'result_contract_ref' => $contractRef,
                'result_reason_code' => null,
            ];
        }

        if ($shadowVerify === self::STATUS_FAIL || $this->isBlockedProofCondition($rawStatus, $reason)) {
            return [
                'result' => self::VERIFICATION_RESULT_BLOCKED,
                'result_contract_ref' => $contractRef,
                'result_reason_code' => $this->extractReasonCode($reason) ?? 'PROOF_CONDITION_FAILED',
            ];
        }

        return [
            'result' => self::VERIFICATION_RESULT_UNKNOWN,
            'result_contract_ref' => $contractRef,
            'result_reason_code' => $this->extractReasonCode($reason) ?? 'INSUFFICIENT_PROOF',
        ];
    }

    private function isBlockedProofCondition(mixed $rawStatus, mixed $reason): bool
    {
        $rawStatusUpper = strtoupper((string) ($rawStatus ?? ''));
        $reasonUpper = strtoupper((string) ($reason ?? ''));

        if (in_array($rawStatusUpper, ['UNSUPPORTED', 'BLOCKED', 'FAIL', 'FAILED', 'ERROR'], true)) {
            return true;
        }

        return str_contains($reasonUpper, 'UNSUPPORTED_HISTORY_CONTRACT')
            || str_contains($reasonUpper, 'NO_CANONICAL_REALM_EVENTS');
    }

    private function extractReasonCode(mixed $reason): ?string
    {
        if ($reason === null || $reason === '') {
            return null;
        }

        $parts = explode(':', (string) $reason, 2);

        return strtoupper(trim($parts[0]));
    }

    /**
     * @return array<string, mixed>
     */
    private function latestProcessHealth(?int $teamId): array
    {
        $observation = SimpleL1NodeObservation::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderByDesc('observed_at')
            ->first();

        if (! $observation) {
            return [
                'status' => self::STATUS_UNKNOWN,
                'note' => 'Process health is separate from semantic health.',
                'source' => 'Runtime',
            ];
        }

        $status = strtolower((string) $observation->status);
        $normalized = in_array($status, ['healthy', 'ok', 'up'], true)
            ? self::STATUS_OK
            : (in_array($status, ['unhealthy', 'down', 'fail', 'failed'], true) ? self::STATUS_FAIL : self::STATUS_UNKNOWN);

        return [
            'status' => $normalized,
            'observer_node' => $observation->observer_node,
            'target_node' => $observation->target_node,
            'observed_at' => $observation->observed_at?->toIso8601String(),
            'note' => 'Process health is separate from semantic health.',
            'source' => 'Runtime',
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $runtimeObservations
     * @return array<string, mixed>
     */
    private function primaryRuntimeProbe(array $runtimeObservations): array
    {
        $reachable = collect($runtimeObservations)
            ->first(fn (array $observation): bool => (bool) ($observation['reachable'] ?? false));

        if (is_array($reachable)) {
            return [
                'reachable' => true,
                'checked_url' => $reachable['checked_url'] ?? null,
                'remote' => is_array($reachable['remote'] ?? null) ? $reachable['remote'] : [],
                'errors' => is_array($reachable['errors'] ?? null) ? $reachable['errors'] : [],
            ];
        }

        return [
            'reachable' => false,
            'checked_url' => null,
            'remote' => [],
            'errors' => collect($runtimeObservations)
                ->mapWithKeys(fn (array $observation): array => [
                    (string) ($observation['checked_url'] ?? $observation['node_id'] ?? 'unknown') => (string) ($observation['error'] ?? 'unreachable'),
                ])
                ->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function probeRuntimeObservations(): array
    {
        return collect($this->runtimeNodeTargets())
            ->map(fn (array $target): array => $this->probeRuntimeObservation($target))
            ->values()
            ->all();
    }

    /**
     * @param  array{node_id: string, url: string}  $target
     * @return array<string, mixed>
     */
    private function probeRuntimeObservation(array $target): array
    {
        $nodeId = $target['node_id'];
        $baseUrl = rtrim($target['url'], '/');
        $observedAt = now()->toIso8601String();

        try {
            $response = Http::timeout((int) config('sovereign.realm_operations.timeout', 10))
                ->acceptJson()
                ->get($baseUrl.'/api/sl1e/runtime/status');

            if (! $response->ok()) {
                return $this->unreachableRuntimeObservation(
                    nodeId: $nodeId,
                    baseUrl: $baseUrl,
                    observedAt: $observedAt,
                    error: 'HTTP '.$response->status(),
                );
            }

            $payload = is_array($response->json()) ? $response->json() : [];
            $identityRealm = is_array($payload['identity_realm'] ?? null) ? $payload['identity_realm'] : [];

            return [
                'node_id' => $nodeId,
                'authority' => 'Runtime',
                'source' => 'Runtime Endpoint',
                'history_head_kind' => $this->stringOrUnknown(
                    $identityRealm['history_head_kind'] ?? $payload['history_head_kind'] ?? null
                ),
                'history_head' => $this->stringOrUnknown(
                    $identityRealm['history_head'] ?? $payload['history_head'] ?? null
                ),
                'state_root' => $this->stringOrUnknown(
                    $identityRealm['state_root'] ?? $payload['state_root'] ?? null
                ),
                'event_count' => is_numeric($identityRealm['event_count'] ?? $payload['event_count'] ?? null)
                    ? (int) ($identityRealm['event_count'] ?? $payload['event_count'])
                    : null,
                'observed_at' => $observedAt,
                'evidence_ref' => 'runtime-observation:'.$nodeId.':'.sha1($baseUrl),
                'reachable' => true,
                'checked_url' => $baseUrl,
                'remote' => $payload,
                'errors' => [],
            ];
        } catch (\Throwable $error) {
            return $this->unreachableRuntimeObservation(
                nodeId: $nodeId,
                baseUrl: $baseUrl,
                observedAt: $observedAt,
                error: $error->getMessage(),
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function unreachableRuntimeObservation(
        string $nodeId,
        string $baseUrl,
        string $observedAt,
        string $error,
    ): array {
        return [
            'node_id' => $nodeId,
            'authority' => 'Runtime',
            'source' => 'Runtime Endpoint',
            'history_head_kind' => self::STATUS_UNKNOWN,
            'history_head' => self::STATUS_UNKNOWN,
            'state_root' => self::STATUS_UNKNOWN,
            'event_count' => null,
            'observed_at' => $observedAt,
            'evidence_ref' => 'runtime-observation:'.$nodeId.':'.sha1($baseUrl),
            'reachable' => false,
            'checked_url' => $baseUrl,
            'remote' => [],
            'errors' => [$baseUrl => $error],
            'error' => $error,
        ];
    }

    /**
     * @return array<int, array{node_id: string, url: string}>
     */
    private function runtimeNodeTargets(): array
    {
        $configured = collect(config('sovereign.realm_operations.runtime_node_observations', []))
            ->filter(fn ($entry) => is_array($entry) && filled($entry['node_id'] ?? null) && filled($entry['url'] ?? null))
            ->map(fn (array $entry): array => [
                'node_id' => (string) $entry['node_id'],
                'url' => rtrim((string) $entry['url'], '/'),
            ])
            ->values();

        if ($configured->isNotEmpty()) {
            return $configured->all();
        }

        return collect($this->runtimeStatusUrls())
            ->values()
            ->map(fn (string $url, int $index): array => [
                'node_id' => 'node-'.($index + 1),
                'url' => $url,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function probeShadowVerification(): array
    {
        $errors = [];

        foreach ($this->runtimeStatusUrls() as $baseUrl) {
            try {
                $response = Http::timeout((int) config('sovereign.realm_operations.timeout', 10))
                    ->acceptJson()
                    ->get($baseUrl.'/api/sl1e/runtime/verification/shadow');

                if ($response->ok()) {
                    $payload = $response->json();

                    return [
                        'reachable' => true,
                        'checked_url' => $baseUrl,
                        'remote' => is_array($payload) ? $payload : [],
                        'errors' => $errors,
                    ];
                }

                $errors[$baseUrl] = 'HTTP '.$response->status();
            } catch (\Throwable $error) {
                $errors[$baseUrl] = $error->getMessage();
            }
        }

        return [
            'reachable' => false,
            'checked_url' => null,
            'remote' => [],
            'errors' => $errors,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function runtimeStatusUrls(): array
    {
        $configured = collect(config('sovereign.realm_operations.runtime_status_urls', []))
            ->filter()
            ->map(fn ($url) => rtrim((string) $url, '/'))
            ->unique()
            ->values()
            ->all();

        if ($configured !== []) {
            return $configured;
        }

        $issuer = config('sovereign.sl1_connect.embedded.issuer_url');
        if (filled($issuer)) {
            return [rtrim((string) $issuer, '/')];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function latestEvidenceMetadata(?int $teamId): array
    {
        $packages = SimpleL1EvidencePackage::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderByDesc('sealed_at')
            ->limit(10)
            ->get();

        $merged = [];
        foreach ($packages as $package) {
            $metadata = is_array($package->metadata) ? $package->metadata : [];
            $realm = is_array($metadata['realm'] ?? null) ? $metadata['realm'] : [];
            $merged = array_merge($merged, $realm, $metadata, $this->metadataFromObservations($package->observations));
        }

        return $merged;
    }

    /**
     * @param  mixed  $observations
     * @return array<string, mixed>
     */
    private function metadataFromObservations(mixed $observations): array
    {
        if (! is_array($observations)) {
            return [];
        }

        $merged = [];
        foreach ($observations as $observation) {
            if (is_array($observation)) {
                $merged = array_merge($merged, $observation);
            }
        }

        return $merged;
    }

    private function evidencePackageCount(?int $teamId): int
    {
        return SimpleL1EvidencePackage::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->count();
    }

    private function latestEvidenceSealedAt(?int $teamId): ?string
    {
        return SimpleL1EvidencePackage::query()
            ->when($teamId, fn ($query) => $query->where('team_id', $teamId))
            ->orderByDesc('sealed_at')
            ->value('sealed_at')
            ?->toIso8601String();
    }

    public function normalizeStatus(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::STATUS_UNKNOWN;
        }

        $normalized = strtoupper((string) $value);

        if (in_array($normalized, ['OK', 'PASS', 'PASSED', 'SUCCESS'], true)) {
            return self::STATUS_OK;
        }

        if (in_array($normalized, ['FAIL', 'FAILED', 'ERROR', 'DIVERGED', 'MISMATCH'], true)) {
            return self::STATUS_FAIL;
        }

        return self::STATUS_UNKNOWN;
    }

    public function normalizeConformanceStatus(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::STATUS_UNKNOWN;
        }

        $normalized = strtoupper((string) $value);

        if (in_array($normalized, ['PASS', 'PASSED', 'OK', 'SUCCESS'], true)) {
            return self::STATUS_OK;
        }

        if (in_array($normalized, ['FAIL', 'FAILED', 'ERROR'], true)) {
            return self::STATUS_FAIL;
        }

        return self::STATUS_UNKNOWN;
    }

    private function stringOrUnknown(mixed $value): string
    {
        if ($value === null || $value === '') {
            return self::STATUS_UNKNOWN;
        }

        return (string) $value;
    }

    private function transitionLabel(mixed $value): string
    {
        if (is_array($value)) {
            return $this->stringOrUnknown($value['type'] ?? $value['id'] ?? null);
        }

        return $this->stringOrUnknown($value);
    }
}
