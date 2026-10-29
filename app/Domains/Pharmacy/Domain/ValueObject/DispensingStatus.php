<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Lifecycle status set for the Dispensing record (16-pharmacy-domain.md §127;
 * ADR-184 D-B).
 *
 * COMPLETED — the medication was released; the reservation was fulfilled and the
 *             stock consumed (Pharm.3 records a dispensing in a single atomic
 *             step, so there is no PLANNED / IN_PROGRESS).
 *
 * REVERSED (§128) arrives with Pharm.3b as a separate compensating record — the
 * row itself is append-only. Backend-only string-constant holder (NOT a PHP
 * enum, NOT #[TypeScript]).
 */
final class DispensingStatus
{
    public const COMPLETED = 'COMPLETED';

    /** @var list<string> */
    public const ALL = [
        self::COMPLETED,
    ];

    public static function assert(string $value): string
    {
        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid DispensingStatus [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }
}
