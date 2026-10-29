<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The nature of an `UpdateProblem` change (12-clinical-domain.md §94; ADR-140
 * D-J / D-R). A CLOSED backend-only set of exactly two values:
 *
 *   - {@see REVISION}   — an ordinary legitimate clinical modification (new
 *                         information, evolving understanding);
 *   - {@see CORRECTION} — correction of previously recorded information; a
 *                         non-blank clinical `reason` is mandatory.
 *
 * The distinction is explicit and permanent in the `clin_problem_history` row
 * and the `ProblemUpdated` event; it does not change identity, status or
 * authorization. C.4 has NO `CorrectProblem` command — correction is
 * `UpdateProblem(changeType = CORRECTION)`.
 *
 * BACKEND-ONLY (ADR-140 D-R): a `final class` of string constants, NOT a PHP
 * `enum` — see {@see ProblemStatus} for why (`generated.ts` must remain
 * UNCHANGED).
 */
final class ProblemChangeType
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
                'ProblemChangeType must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
