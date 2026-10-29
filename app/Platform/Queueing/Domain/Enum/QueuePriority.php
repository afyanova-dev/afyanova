<?php

declare(strict_types=1);

namespace App\Platform\Queueing\Domain\Enum;

use InvalidArgumentException;

enum QueuePriority: string
{
    case ROUTINE = 'ROUTINE';
    case PRIORITY = 'PRIORITY';
    case URGENT = 'URGENT';
    case EMERGENCY = 'EMERGENCY';

    public static function fromTriagePriority(string $priority): self
    {
        return match (strtoupper($priority)) {
            'EMERGENCY' => self::EMERGENCY,
            'URGENT' => self::URGENT,
            'HIGH' => self::PRIORITY,
            'NORMAL', 'LOW' => self::ROUTINE,
            default => throw new InvalidArgumentException("Unknown triage priority [{$priority}]."),
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::EMERGENCY => 40,
            self::URGENT => 30,
            self::PRIORITY => 20,
            self::ROUTINE => 10,
        };
    }
}
