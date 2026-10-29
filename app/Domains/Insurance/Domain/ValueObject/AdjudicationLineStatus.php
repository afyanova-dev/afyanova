<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Adjudication outcome for an individual claim line item
 * (21-insurance-and-payer-domain.md §84–§85; ADR-205).
 */
final class AdjudicationLineStatus
{
    public const APPROVED = 'APPROVED';

    public const DENIED = 'DENIED';

    public const PENDED = 'PENDED';

    public const REJECTED = 'REJECTED';

    /** @var list<string> */
    public const ALL = [
        self::APPROVED,
        self::DENIED,
        self::PENDED,
        self::REJECTED,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid adjudication line status [%s]. Permitted: %s.',
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
