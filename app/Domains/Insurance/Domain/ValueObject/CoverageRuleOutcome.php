<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The stored outcome of a plan coverage rule
 * (21-insurance-and-payer-domain.md §33, §44, §57; ADR-200 D-A):
 * COVERED | NOT_COVERED | REQUIRES_AUTHORIZATION.
 *
 * Distinct from the DERIVED {@see CoverageDecisionStatus} the `CoverageDecision`
 * reports (which also has PARTIALLY_COVERED and UNKNOWN). Open-coded +
 * SET-validated.
 */
final class CoverageRuleOutcome
{
    public const COVERED = 'COVERED';

    public const NOT_COVERED = 'NOT_COVERED';

    public const REQUIRES_AUTHORIZATION = 'REQUIRES_AUTHORIZATION';

    /** @var list<string> */
    public const ALL = [self::COVERED, self::NOT_COVERED, self::REQUIRES_AUTHORIZATION];

    public static function assert(string $value): string
    {
        $value = strtoupper(trim($value));

        if (! in_array($value, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid coverage-rule outcome [%s]. Permitted: %s.',
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
