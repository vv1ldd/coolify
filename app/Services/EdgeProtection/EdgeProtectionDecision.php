<?php

namespace App\Services\EdgeProtection;

final class EdgeProtectionDecision
{
    public const ALLOW = 'allow';

    public const CHALLENGE = 'challenge';

    public const BLOCK = 'block';

    public function __construct(
        public readonly string $action,
        public readonly string $reason,
        public readonly int $status = 404,
        public readonly array $context = [],
    ) {}

    public static function allow(string $reason, array $context = []): self
    {
        return new self(self::ALLOW, $reason, 200, $context);
    }

    public static function challenge(string $reason, array $context = []): self
    {
        return new self(self::CHALLENGE, $reason, 302, $context);
    }

    public static function block(string $reason, int $status = 404, array $context = []): self
    {
        return new self(self::BLOCK, $reason, $status, $context);
    }

    public function allows(): bool
    {
        return $this->action === self::ALLOW;
    }

    public function challenges(): bool
    {
        return $this->action === self::CHALLENGE;
    }

    public function blocks(): bool
    {
        return $this->action === self::BLOCK;
    }
}
