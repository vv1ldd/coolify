<?php

namespace App\Enums;

enum ProtectionLevel: string
{
    case NORMAL = 'normal';
    case ELEVATED = 'elevated';
    case HIGH = 'high';
    case CRITICAL = 'critical';
    case EMERGENCY = 'emergency';

    public static function fromSignalSeverity(mixed $severity): self
    {
        if (is_string($severity)) {
            $level = self::tryFrom(strtolower(trim($severity)));
            if ($level) {
                return $level;
            }
        }

        $score = min(max((int) $severity, 0), 100);

        return match (true) {
            $score >= 90 => self::EMERGENCY,
            $score >= 75 => self::CRITICAL,
            $score >= 50 => self::HIGH,
            $score >= 25 => self::ELEVATED,
            default => self::NORMAL,
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::NORMAL => 0,
            self::ELEVATED => 1,
            self::HIGH => 2,
            self::CRITICAL => 3,
            self::EMERGENCY => 4,
        };
    }

    public function isAtLeast(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }
}
