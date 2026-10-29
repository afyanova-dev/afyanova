<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

enum ServiceCapability: string
{
    case TRIAGE = 'TRIAGE';
    case CONSULTATION = 'CONSULTATION';
    case PHARMACY = 'PHARMACY';
    case LABORATORY = 'LABORATORY';
    case RADIOLOGY = 'RADIOLOGY';
    case CASHIER = 'CASHIER';
    case PROCEDURE = 'PROCEDURE';

    public function requiresOrderProvenance(): bool
    {
        return match ($this) {
            self::PHARMACY, self::LABORATORY, self::RADIOLOGY, self::PROCEDURE => true,
            default => false,
        };
    }
}
