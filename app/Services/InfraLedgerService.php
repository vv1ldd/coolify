<?php

namespace App\Services;

use App\Models\InfraLedger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Sovereign Infrastructure Intent Ledger Service
 *
 * Records infrastructure STATE TRANSITIONS — not stdout logs.
 * Each entry is a verifiable Document of Intent in a SHA-256 hash chain.
 *
 * Architecture:
 *   Stage 1: Co-located PostgreSQL table (current)
 *   Stage 2: Dedicated append-only ledger DB
 *   Stage 3: Merkle root anchoring to Simple L1
 *
 * DID:SYS Identity Model:
 *   DID:SYS|USER:#42               — human admin actor
 *   DID:SYS|NODE:#validator-eu-west — infrastructure node
 *   DID:SYS|SERVICE:#deployment-engine — automation service
 *   DID:SYS|JOB:#scheduled-backup  — scheduled job
 */
class InfraLedgerService
{
    private const CONSTITUTION_ID = 'sovereign-infra-v1';

    private const DETERMINISM_MODE = 'strict-mdk-v1.1';

    /**
     * Record an infrastructure state transition as a verifiable Intent Document.
     *
     * @param  string  $eventType  e.g. 'application.deploy', 'server.restart'
     * @param  Model|null  $entity  The target infrastructure entity (Server, Application, etc.)
     * @param  array  $payload  Sanitized intent parameters (no secrets/PII)
     * @param  array|null  $inputState  State snapshot BEFORE the transition
     * @param  array|null  $outputState  State snapshot AFTER the transition (result, exit_code, etc.)
     * @param  string|null  $actor  Override DID:SYS identity (auto-resolved if null)
     * @param  int|null  $teamId  Explicit team override (auto-resolved if null)
     */
    public function record(
        string $eventType,
        ?Model $entity = null,
        array $payload = [],
        ?array $inputState = null,
        ?array $outputState = null,
        ?string $actor = null,
        ?int $teamId = null,
    ): InfraLedger {
        return DB::transaction(function () use (
            $eventType, $entity, $payload, $inputState, $outputState, $actor, $teamId
        ) {
            $kernelFingerprint = $this->kernelFingerprint();

            // --- Resolve Team Namespace ---
            $resolvedTeamId = $teamId ?? currentTeam()?->id;

            // --- Resolve Actor Identity (DID:SYS) ---
            $triggerSource = $actor ?? $this->resolveActor();

            // --- Get previous entry in this team's chain ---
            $lastEntry = InfraLedger::query()
                ->when($resolvedTeamId, fn ($q) => $q->where('team_id', $resolvedTeamId))
                ->orderBy('id', 'desc')
                ->lockForUpdate()
                ->first();
            $previousFingerprint = $lastEntry?->fingerprint;

            $createdAt = now()->toDateTimeString();

            // --- Build the Document of Intent ---
            // This is the canonical structure for the hash — order matters.
            $intentDocument = [
                'prev' => $previousFingerprint,
                'type' => $eventType,
                'entity_id' => (string) $entity?->getKey(),
                'entity_type' => $entity ? get_class($entity) : null,
                'payload' => $this->sanitizePayload($payload),
                'ts' => $createdAt,
                'source' => $triggerSource,
                'in' => $inputState,
                'out' => $outputState,
                // Deterministic anchor — kernel constitution proof
                'kernel_fp' => $kernelFingerprint,
            ];

            // Deterministic canonical serialization → SHA-256
            $canonicalJson = $this->canonicalJson($intentDocument);
            $entryFingerprint = hash('sha256', $canonicalJson);

            return InfraLedger::create([
                'team_id' => $resolvedTeamId,
                'trigger_source' => $triggerSource,
                'event_type' => $eventType,
                'entity_type' => $entity ? get_class($entity) : null,
                'entity_id' => $entity?->getKey(),
                'payload' => $this->sanitizePayload($payload),
                'input_state' => $inputState,
                'output_state' => $outputState,
                'fingerprint' => $entryFingerprint,
                'previous_fingerprint' => $previousFingerprint,
                'created_at' => $createdAt,
                'meta' => [
                    'kernel_fingerprint' => $kernelFingerprint,
                    'determinism' => self::DETERMINISM_MODE,
                    'constitution' => self::CONSTITUTION_ID,
                ],
            ]);
        });
    }

    /**
     * Record a system-level (cross-team) infrastructure event.
     * Used for global events like node registration, validator health, etc.
     */
    public function recordSystem(
        string $eventType,
        array $payload = [],
        ?array $inputState = null,
        ?array $outputState = null,
    ): InfraLedger {
        return $this->record(
            eventType: $eventType,
            payload: $payload,
            inputState: $inputState,
            outputState: $outputState,
            actor: 'DID:SYS|SERVICE:#sovereign-control-plane',
            teamId: null,
        );
    }

    /**
     * Verify the cryptographic integrity of a team's ledger chain.
     * Returns valid=true only if every fingerprint in the chain is correct.
     *
     * @param  int|null  $teamId  Team to verify (null = system chain)
     * @param  int|null  $limit  Verify only the last N entries (null = full scan)
     */
    public function verifyIntegrity(?int $teamId = null, ?int $limit = null): array
    {
        $query = InfraLedger::query()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId));

        if ($limit) {
            $entries = $query->orderBy('id', 'desc')->take($limit)->get()->reverse()->values();
        } else {
            $entries = $query->orderBy('id', 'asc')->get();
        }

        if ($entries->isEmpty()) {
            return ['valid' => true, 'errors' => [], 'count' => 0, 'message' => 'Empty chain — no intents recorded yet.'];
        }

        $errors = [];
        $expectedPrev = $entries->first()?->previous_fingerprint;

        foreach ($entries as $entry) {
            // 1. Verify chain linkage
            if ($entry->previous_fingerprint !== $expectedPrev) {
                $errors[] = [
                    'id' => $entry->id,
                    'type' => 'CHAIN_BREAK',
                    'detail' => "Previous fingerprint mismatch at event #{$entry->id} ({$entry->event_type})",
                ];
            }

            // 2. Recompute fingerprint from canonical document
            $intentDocument = [
                'prev' => $entry->previous_fingerprint,
                'type' => $entry->event_type,
                'entity_id' => (string) $entry->entity_id,
                'entity_type' => $entry->entity_type,
                'payload' => $entry->payload,
                'ts' => $entry->created_at->toDateTimeString(),
                'source' => $entry->trigger_source,
                'in' => $entry->input_state,
                'out' => $entry->output_state,
                'kernel_fp' => data_get($entry->meta, 'kernel_fingerprint'),
            ];

            $recomputed = hash('sha256', $this->canonicalJson($intentDocument));

            if ($entry->fingerprint !== $recomputed) {
                $errors[] = [
                    'id' => $entry->id,
                    'type' => 'FINGERPRINT_MISMATCH',
                    'detail' => "Data corruption detected at event #{$entry->id} ({$entry->event_type})",
                ];
            }

            $expectedPrev = $entry->fingerprint;
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'count' => $entries->count(),
        ];
    }

    /**
     * Auto-resolve the DID:SYS actor identity from the current request context.
     */
    private function resolveActor(): string
    {
        $user = auth()->user();

        if ($user) {
            $roles = method_exists($user, 'getRoleNames')
                ? $user->getRoleNames()->sort()->values()->implode(',')
                : 'member';
            $roleTag = $roles ? "[{$roles}]" : '[USER]';

            return "DID:SYS|USER:{$roleTag}:#{$user->id}";
        }

        // Running in a queue job, scheduler, or CLI context
        if (app()->runningInConsole()) {
            return 'DID:SYS|SERVICE:#sovereign-scheduler';
        }

        return 'DID:SYS|SERVICE:#system';
    }

    private function kernelFingerprint(): string
    {
        return hash('sha256', $this->canonicalJson([
            'constitution' => self::CONSTITUTION_ID,
            'determinism' => self::DETERMINISM_MODE,
            'math_mode' => 'atto',
            'strict_identity' => true,
        ]));
    }

    private function canonicalJson(mixed $value): string
    {
        return json_encode(
            $this->sortRecursively($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->sortRecursively($item);
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }

    /**
     * Sanitize payload — mask sensitive fields before hashing.
     * This ensures PII/secrets never enter the permanent ledger.
     */
    private function sanitizePayload(array $payload): array
    {
        $sensitiveKeys = [
            'password', 'secret', 'token', 'key', 'private_key',
            'ssh_key', 'api_key', 'webhook_secret', 'passphrase',
            'email', 'ip', 'ipv4', 'ipv6',
        ];

        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = $this->sanitizePayload($v);
            } elseif (in_array(strtolower($k), $sensitiveKeys, true)) {
                $payload[$k] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
