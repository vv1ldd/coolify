<?php

namespace App\Http\Middleware;

use App\Models\KernelPartner;
use App\Services\SourceLedgerService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class VerifyFinancialSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $timestamp = (string) $request->header('X-Financial-Timestamp');
        $signature = (string) $request->header('X-Financial-Signature');

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return $this->reject($request, 'source.signature.missing', 'Missing financial signature.', 401);
        }

        $tolerance = (int) config('digital-goods-source.signature_tolerance_seconds', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return $this->reject($request, 'source.signature.expired', 'Financial signature expired.', 401);
        }

        $secret = $this->secretFor($request);
        if (! is_string($secret) || $secret === '') {
            return $this->reject($request, 'source.signature.secret_missing', 'Financial signature secret is not configured.', 401);
        }

        $body = $request->getContent() ?: '';
        $path = '/'.ltrim($request->path(), '/');
        $expected = hash_hmac('sha256', $timestamp.'.'.strtoupper($request->method()).'.'.$path.'.'.$body, $secret);
        $legacyExpected = hash_hmac('sha256', $timestamp.'.'.$body, $secret);

        if (! hash_equals($expected, $signature) && ! hash_equals($legacyExpected, $signature)) {
            return $this->reject($request, 'source.signature.invalid', 'Invalid financial signature.', 401);
        }

        $replayKey = 'digital-goods-source:financial-signature:'.hash('sha256', implode('|', [
            (string) $request->header('X-Client-Id'),
            $timestamp,
            strtoupper($request->method()),
            $path,
            $body,
            $signature,
        ]));

        if (Cache::has($replayKey)) {
            return $this->reject($request, 'source.signature.replay_rejected', 'Financial signature replay rejected.', 409);
        }

        Cache::put($replayKey, true, $tolerance);

        return $next($request);
    }

    private function secretFor(Request $request): ?string
    {
        $partner = $request->attributes->get('kernel_partner');
        if ($partner instanceof KernelPartner && is_string($partner->financial_secret)) {
            return $partner->financial_secret;
        }

        return config('digital-goods-source.financial_secret');
    }

    private function reject(Request $request, string $eventType, string $message, int $status): Response
    {
        $receipt = null;

        try {
            $partner = $request->attributes->get('kernel_partner');
            $partnerExternalId = $partner instanceof KernelPartner
                ? $partner->external_id
                : ((string) $request->header('X-Client-Id') ?: null);
            $path = '/'.ltrim($request->path(), '/');
            $body = $request->getContent() ?: '';

            $entry = app(SourceLedgerService::class)->record(
                eventType: $eventType,
                payload: [
                    'method' => strtoupper($request->method()),
                    'path' => $path,
                    'body_hash' => hash('sha256', $body),
                    'signature_hash' => $request->header('X-Financial-Signature') ? hash('sha256', (string) $request->header('X-Financial-Signature')) : null,
                ],
                outputState: [
                    'status' => $status,
                    'message' => $message,
                ],
                scope: app(SourceLedgerService::class)->scopeFor(null, $partnerExternalId),
                partnerExternalId: $partnerExternalId,
                reference: (string) ($request->input('reference') ?: $request->input('referenceCode') ?: ''),
            );
            $receipt = app(SourceLedgerService::class)->receipt($entry);
        } catch (\Throwable) {
            $receipt = null;
        }

        $payload = [
            'success' => false,
            'message' => $message,
            'source_ledger_receipt' => $receipt,
        ];

        if ($receipt === null) {
            unset($payload['source_ledger_receipt']);
        }

        return response()->json($payload, $status);
    }
}
