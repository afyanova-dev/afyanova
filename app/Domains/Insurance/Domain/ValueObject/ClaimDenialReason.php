<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Standard claim denial reason codes
 * (21-insurance-and-payer-domain.md §87; ADR-205).
 */
final class ClaimDenialReason
{
    public const NOT_COVERED = 'NOT_COVERED';

    public const AUTH_MISSING = 'AUTH_MISSING';

    public const LIMIT_EXCEEDED = 'LIMIT_EXCEEDED';

    public const MEMBER_INELIGIBLE = 'MEMBER_INELIGIBLE';

    public const TIMELY_FILING_EXCEEDED = 'TIMELY_FILING_EXCEEDED';

    public const CODING_ERROR = 'CODING_ERROR';

    public const DOCUMENTATION_MISSING = 'DOCUMENTATION_MISSING';

    public const SERVICE_EXCLUDED = 'SERVICE_EXCLUDED';

    public const DUPLICATE = 'DUPLICATE';

    public const OTHER = 'OTHER';

    /** @var list<string> */
    public const ALL = [
        self::NOT_COVERED,
        self::AUTH_MISSING,
        self::LIMIT_EXCEEDED,
        self::MEMBER_INELIGIBLE,
        self::TIMELY_FILING_EXCEEDED,
        self::CODING_ERROR,
        self::DOCUMENTATION_MISSING,
        self::SERVICE_EXCLUDED,
        self::DUPLICATE,
        self::OTHER,
    ];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid claim denial reason [%s]. Permitted: %s (§87 / ADR-205).',
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
