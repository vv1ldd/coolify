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

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?int $teamId = null): array
    {
        $evidence = $this->latestEvidenceMetadata($teamId);
        $runtimeProbe = $this->probeRuntimeStatus();
        $processHealth = $this->latestProcessHealth($teamId);

        return [
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
            'verification' => $this->verificationSection($evidence),
            'process_health' => $processHealth,
            'evidence_refs' => [
                'package_count' => $this->evidencePackageCount($teamId),
                'latest_package_sealed_at' => $this->latestEvidenceSealedAt($teamId),
                'runtime_checked_url' => $runtimeProbe['checked_url'] ?? null,
            ],
        ];
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
    private function verificationSection(array $evidence): array
    {
        return [
            'semantic_health' => $this->normalizeStatus($evidence['semantic_health'] ?? null),
            'shadow_verify' => $this->normalizeStatus($evidence['shadow_verify'] ?? $evidence['shadow_verification'] ?? null),
            'conformance' => $this->normalizeConformanceStatus($evidence['conformance'] ?? $evidence['certification_status'] ?? null),
            'source' => 'Verifier',
        ];
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
     * @return array<string, mixed>
     */
    private function probeRuntimeStatus(): array
    {
        $errors = [];

        foreach ($this->runtimeStatusUrls() as $baseUrl) {
            try {
                $response = Http::timeout((int) config('sovereign.realm_operations.timeout', 10))
                    ->acceptJson()
                    ->get($baseUrl.'/api/sl1e/runtime/status');

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
