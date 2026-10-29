<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Reconciliation state of an individual remittance advice line item
 * (21-insurance-and-payer-domain.md §98–§104; ADR-205).
 */
final class RemittanceLineStatus
{
    public const MATCHED = 'MATCHED';

    public const UNMATCHED = 'UNMATCHED';

    public const DISCREPANT = 'DISCREPANT';

    /** @var list<string> */
    public const ALL = [
        self::MATCHED,
        self::UNMATCHED,
        self::DISCREPANT,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid remittance line status [%s]. Permitted: %s.',
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
