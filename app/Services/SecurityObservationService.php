<?php

namespace App\Services;

use App\Models\SecurityObservation;
use Illuminate\Support\Facades\Log;

class SecurityObservationService
{
    public function __construct(private readonly InfraLedgerService $ledger) {}

    /**
     * @param  array{
     *     source_ip?: string|null,
     *     trace_id?: string|null,
     *     session_id?: string|null,
     *     layer?: string|null,
     *     signal: string,
     *     score: int,
     *     action?: string|null,
     *     method?: string|null,
     *     path?: string|null,
     *     user_agent?: string|null,
     *     metadata?: array|null,
     *     observed_at?: mixed
     * }  $payload
     */
    public function record(int $teamId, array $payload): SecurityObservation
    {
        $sourceIp = data_get($payload, 'source_ip');
        $sourceHash = $this->sourceHash($sourceIp ?: 'unknown');
        $score = min(max((int) data_get($payload, 'score', 0), 0), 100);

        $observation = SecurityObservation::create([
            'team_id' => $teamId,
            'trace_id' => data_get($payload, 'trace_id'),
            'session_id' => data_get($payload, 'session_id'),
            'source_ip' => $sourceIp,
            'source_hash' => $sourceHash,
            'layer' => data_get($payload, 'layer', 'L2'),
            'signal' => data_get($payload, 'signal'),
            'score' => $score,
            'action' => data_get($payload, 'action', 'observe'),
            'method' => data_get($payload, 'method'),
            'path' => data_get($payload, 'path'),
            'user_agent' => data_get($payload, 'user_agent'),
            'metadata' => data_get($payload, 'metadata', []),
            'observed_at' => data_get($payload, 'observed_at') ?: now(),
        ]);

        $this->recordLedgerEvent($teamId, $observation);

        return $observation;
    }

    public function sourceHash(string $source): string
    {
        return hash_hmac('sha256', $source, config('app.key') ?: 'coolify-security-observation');
    }

    public function sourceScore(int $teamId, string $sourceHash, int $windowMinutes = 15): int
    {
        $windowMinutes = min(max($windowMinutes, 1), 1440);

        return (int) SecurityObservation::query()
            ->where('team_id', $teamId)
            ->where('source_hash', $sourceHash)
            ->where('observed_at', '>=', now()->subMinutes($windowMinutes))
            ->sum('score');
    }

    private function recordLedgerEvent(int $teamId, SecurityObservation $observation): void
    {
        try {
            $this->ledger->record(
                eventType: 'security.observation',
                entity: $observation,
                payload: [
                    'source_hash' => $observation->source_hash,
                    'ip' => $observation->source_ip,
                    'trace_id' => $observation->trace_id,
                    'session_id' => $observation->session_id,
                    'layer' => $observation->layer,
                    'signal' => $observation->signal,
                    'score' => $observation->score,
                    'action' => $observation->action,
                    'method' => $observation->method,
                    'path' => $observation->path,
                ],
                inputState: null,
                outputState: [
                    'recorded' => true,
                    'observation_uuid' => $observation->uuid,
                ],
                actor: 'DID:SYS|SERVICE:#security-observation',
                teamId: $teamId,
            );
        } catch (\Throwable $e) {
            Log::warning('Security observation ledger recording failed.', [
                'observation_uuid' => $observation->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
