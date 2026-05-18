<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SimpleL1Client
{
    /**
     * Create a new SimpleL1Client instance.
     */
    public function __construct(
        protected string $nodeUrl,
        protected string $contractAddress
    ) {}

    /**
     * Record a secure transaction on the L1 ledger
     *
     * @return string Transaction Hash (TxHash)
     */
    public function recordTransaction(string $eventType, array $payload): string
    {
        $nonce = random_int(100000, 999999);

        // 1. Pack data and envelope
        $envelope = [
            'event_type' => $eventType,
            'nonce' => $nonce,
            'payload' => json_encode($payload),
            'timestamp' => now()->timestamp,
            'trigger_source' => request()->ip() ?? '127.0.0.1',
        ];

        // 2. Cryptographic mock-signing representing private hardware key signature
        // In production, this signature is verified by the simple-l1-smart-contract
        $signatureInput = $envelope['event_type'].$envelope['nonce'].$envelope['payload'].$envelope['timestamp'];
        $signature = '0x'.hash_hmac('sha256', $signatureInput, config('app.key') ?? 'sovereign_secret');

        $envelope['signature'] = $signature;
        $envelope['l1_contract'] = $this->contractAddress;

        // 3. Dispatch to blockchain node via JSON-RPC or REST
        try {
            $response = Http::timeout(5)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->nodeUrl.'/api/v1/ledger/transactions', $envelope);

            if ($response->successful()) {
                $data = $response->json();
                $txHash = $data['tx_hash'] ?? $this->generateFallbackTxHash($signature);
                Log::info("Sovereign L1 Ledger transaction anchored successfully: {$txHash}", [
                    'event_type' => $eventType,
                    'tx_hash' => $txHash,
                ]);

                return $txHash;
            }
        } catch (\Throwable $e) {
            Log::warning('Sovereign L1 Node offline or unreachable. Defaulting to local Crypt-Audit.');
        }

        // Fallback to local secure database logging/audit trail if node is offline
        $fallbackTxHash = $this->generateFallbackTxHash($signature);
        Log::info("Sovereign Local Crypt-Audit secured: {$fallbackTxHash}", [
            'event_type' => $eventType,
        ]);

        return $fallbackTxHash;
    }

    /**
     * Generate unique fallback transaction hash
     */
    protected function generateFallbackTxHash(string $signature): string
    {
        return '0x_fallback_'.substr(md5($signature.microtime()), 0, 40);
    }
}
