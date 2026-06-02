<?php

namespace App\Services\Provider;

use RuntimeException;

final class ProviderApiException extends RuntimeException
{
    public function __construct(
        public readonly string $provider,
        string $message,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }

    public static function requestFailed(string $provider, ?int $status = null): self
    {
        $suffix = $status ? " with HTTP status {$status}" : '';

        return new self($provider, "Provider {$provider} request failed{$suffix}.", $status);
    }
}
