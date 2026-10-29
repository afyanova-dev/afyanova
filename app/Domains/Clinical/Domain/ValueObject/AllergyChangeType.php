<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The nature of an `UpdateAllergy` content change (12-clinical-domain.md §94;
 * ADR-141 D-K / D-J). A CLOSED backend-only set of exactly two values:
 *
 *   - {@see REVISION}   — an ordinary legitimate clinical modification (new
 *                         information, evolving understanding); `reason` MUST be
 *                         null / absent;
 *   - {@see CORRECTION} — correction of previously recorded information; a
 *                         non-blank clinical `reason` is MANDATORY.
 *
 * The distinction is explicit and permanent in the `clin_allergy_history` row
 * and the `AllergyUpdated` event; it does not change identity, either status
 * axis, or authorization. C.5 has NO `CorrectAllergy` command — correction is
 * `UpdateAllergy(changeType = CORRECTION)` (the ADR-140 D-J precedent).
 *
 * BACKEND-ONLY (ADR-141 D-V): a `final class` of string constants, NOT a PHP
 * `enum` — see {@see AllergyStatus} for why (`generated.ts` must remain
 * UNCHANGED).
 */
final class AllergyChangeType
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
                'AllergyChangeType must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
