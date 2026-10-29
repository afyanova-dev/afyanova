<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The coordination-of-benefits order of a coverage for a patient
 * (21-insurance-and-payer-domain.md §21, §22, §23, §143; ADR-199 D-A).
 *
 * One ACTIVE coverage per priority per patient (the partial unique index) keeps
 * the primary → secondary → tertiary order unambiguous (§143). Open-coded +
 * SET-validated.
 */
final class CoveragePriority
{
    public const PRIMARY = 'PRIMARY';

    public const SECONDARY = 'SECONDARY';

    public const TERTIARY = 'TERTIARY';

    /** @var list<string> */
    public const ALL = [self::PRIMARY, self::SECONDARY, self::TERTIARY];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid coverage priority [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $value;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }

    /** Sort rank for "ACTIVE first, then by priority" reader ordering. */
    public static function rank(string $value): int
    {
        return match (strtoupper(trim($value))) {
            self::PRIMARY => 0,
            self::SECONDARY => 1,
            self::TERTIARY => 2,
            default => 9,
        };
    }
}
