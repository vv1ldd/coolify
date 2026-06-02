<?php

namespace App\Services\EdgeProtection;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class EdgeProtectionService
{
    public function challengePath(): string
    {
        return (string) config('sovereign.edge_protection.challenge_path', '/__edge/challenge');
    }

    public function verifyPath(): string
    {
        return (string) config('sovereign.edge_protection.verify_path', '/__edge/challenge/verify');
    }

    public function cookieName(): string
    {
        return (string) config('sovereign.edge_protection.cookie_name', 'coolify_edge_proof');
    }

    public function safeReturnTo(mixed $returnTo): string
    {
        if (! is_string($returnTo) && ! is_numeric($returnTo)) {
            return '/';
        }

        $returnTo = trim((string) $returnTo);

        if ($returnTo === ''
            || ! str_starts_with($returnTo, '/')
            || str_starts_with($returnTo, '//')
            || str_contains($returnTo, "\r")
            || str_contains($returnTo, "\n")
            || str_contains($returnTo, '\\')) {
            return '/';
        }

        $parts = parse_url($returnTo);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) {
            return '/';
        }

        $path = (string) data_get($parts, 'path', '/');
        if ($path === '' || ! str_starts_with($path, '/') || str_starts_with($path, $this->challengePath())) {
            return '/';
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    public function challengeUrl(Request $request): string
    {
        return $this->challengePath().'?'.http_build_query([
            'return_to' => $this->safeReturnTo($request->getRequestUri()),
        ]);
    }

    public function createChallengeToken(Request $request, string $returnTo): string
    {
        return $this->signPayload([
            'type' => 'challenge',
            'host' => $this->hostFingerprint($request),
            'ua_hash' => $this->userAgentHash($request),
            'return_to' => $this->safeReturnTo($returnTo),
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => now()->timestamp,
            'exp' => now()->addSeconds($this->challengeTtlSeconds())->timestamp,
        ]);
    }

    public function verifyChallengeToken(Request $request, ?string $token): ?array
    {
        $payload = $this->verifySignedPayload($token);
        if (! $payload || data_get($payload, 'type') !== 'challenge') {
            return null;
        }

        if (! $this->payloadMatchesRequest($payload, $request)) {
            return null;
        }

        return $payload;
    }

    public function hasValidProof(Request $request): bool
    {
        $payload = $this->verifySignedPayload($request->cookie($this->cookieName()));
        if (! $payload || data_get($payload, 'type') !== 'proof') {
            return false;
        }

        return $this->payloadMatchesRequest($payload, $request);
    }

    public function createProofValue(Request $request): string
    {
        return $this->signPayload([
            'type' => 'proof',
            'host' => $this->hostFingerprint($request),
            'ua_hash' => $this->userAgentHash($request),
            'nonce' => bin2hex(random_bytes(16)),
            'iat' => now()->timestamp,
            'exp' => now()->addMinutes($this->proofTtlMinutes())->timestamp,
        ]);
    }

    public function queueProofCookie(Response $response, Request $request): Response
    {
        Cookie::queue(Cookie::make(
            $this->cookieName(),
            $this->createProofValue($request),
            $this->proofTtlMinutes(),
            '/',
            null,
            $request->isSecure(),
            true,
            false,
            'Lax',
        ));

        return $response;
    }

    public function recordDecision(EdgeProtectionDecision $decision, Request $request): void
    {
        if ($decision->allows() || ! (bool) config('sovereign.edge_protection.log_decisions', true)) {
            return;
        }

        Log::info('Edge protection decision.', [
            'action' => $decision->action,
            'reason' => $decision->reason,
            'host' => $request->getHost(),
            'path' => $request->path(),
            'method' => $request->method(),
            'user_agent_hash' => $this->userAgentHash($request),
        ]);
    }

    private function payloadMatchesRequest(array $payload, Request $request): bool
    {
        $expiresAt = (int) data_get($payload, 'exp', 0);
        $issuedAt = (int) data_get($payload, 'iat', 0);

        if ($expiresAt < now()->timestamp || $issuedAt > now()->addMinute()->timestamp) {
            return false;
        }

        return hash_equals((string) data_get($payload, 'host', ''), $this->hostFingerprint($request))
            && hash_equals((string) data_get($payload, 'ua_hash', ''), $this->userAgentHash($request));
    }

    private function signPayload(array $payload): string
    {
        $body = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $body.'.'.hash_hmac('sha256', $body, $this->signingKey());
    }

    private function verifySignedPayload(?string $value): ?array
    {
        if (! is_string($value) || ! str_contains($value, '.')) {
            return null;
        }

        [$body, $signature] = explode('.', $value, 2);
        $expected = hash_hmac('sha256', $body, $this->signingKey());
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($body);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);

        return is_array($payload) ? $payload : null;
    }

    private function proofTtlMinutes(): int
    {
        return max((int) config('sovereign.edge_protection.proof_ttl_minutes', 10), 1);
    }

    private function challengeTtlSeconds(): int
    {
        return max((int) config('sovereign.edge_protection.challenge_ttl_seconds', 120), 10);
    }

    private function hostFingerprint(Request $request): string
    {
        return strtolower($request->getHost());
    }

    private function userAgentHash(Request $request): string
    {
        return hash('sha256', (string) $request->userAgent());
    }

    private function signingKey(): string
    {
        return (string) (config('app.key') ?: 'coolify-edge-protection');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        return base64_decode(strtr($value.str_repeat('=', (4 - strlen($value) % 4) % 4), '-_', '+/'), true);
    }
}
