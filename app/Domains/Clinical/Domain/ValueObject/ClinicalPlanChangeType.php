<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The nature of an `UpdateClinicalPlan` change (ADR-143 D-E):
 *   - REVISION: ordinary clinical modification / evolution
 *   - CORRECTION: correction of previously recorded plan content; requires non-blank clinical reason
 *
 * Backend-only string-constant holder (NOT a PHP enum, NOT #[TypeScript]).
 */
final class ClinicalPlanChangeType
{
    public const REVISION = 'REVISION';

    public const CORRECTION = 'CORRECTION';

    /** @var list<string> */
    public const ALL = [self::REVISION, self::CORRECTION];

    private function __construct() {}

    /** @return list<string> */
    public static function all(): array
    {
        return self::ALL;
    }

    public static function isValid(string $value): bool
    {
        return in_array($value, self::ALL, true);
    }

    public static function assert(string $value): string
    {
        if (! self::isValid($value)) {
            throw new InvalidArgumentException(sprintf(
                'ClinicalPlanChangeType must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
