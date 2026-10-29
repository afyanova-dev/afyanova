<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The service category a coverage rule applies to
 * (21-insurance-and-payer-domain.md §31, §32, §69; ADR-200 D-A).
 *
 * The SAME 7 values as Billing's `BillingServiceCategory` (ADR-188 D-A) — a
 * shared CODED vocabulary, NOT a shared table. Billing tags each
 * `bill_billing_services` row with one of these; an Insurance coverage rule is
 * keyed by the same category. Open-coded + SET-validated.
 */
final class CoveredServiceCategory
{
    public const CONSULTATION = 'CONSULTATION';

    public const PROCEDURE = 'PROCEDURE';

    public const LABORATORY = 'LABORATORY';

    public const RADIOLOGY = 'RADIOLOGY';

    public const MEDICATION = 'MEDICATION';

    public const BED_DAY = 'BED_DAY';

    public const OTHER = 'OTHER';

    /** @var list<string> */
    public const ALL = [
        self::CONSULTATION,
        self::PROCEDURE,
        self::LABORATORY,
        self::RADIOLOGY,
        self::MEDICATION,
        self::BED_DAY,
        self::OTHER,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid covered-service category [%s]. Permitted: %s.',
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
}
