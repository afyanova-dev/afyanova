<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Permitted reasons for ending an occupancy in `bw_bed_occupancies`
 * (24-bed-and-ward-management-domain.md §6, §8.2; ADR-148 D-B).
 *
 * A closed value object (final class + ALL + assert() / isValid()) — NOT a PHP enum.
 */
final class BedEndReason
{
    public const DISCHARGE = 'DISCHARGE';

    public const TRANSFER = 'TRANSFER';

    public const DEATH = 'DEATH';

    public const CORRECTION = 'CORRECTION';

    /** @var list<string> */
    public const ALL = [
        self::DISCHARGE,
        self::TRANSFER,
        self::DEATH,
        self::CORRECTION,
    ];

    public static function assert(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid BedEndReason [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $normalized;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
