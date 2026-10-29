<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * Longitudinal problem-list entry lifecycle state (12-clinical-domain.md §26,
 * §74; ADR-140 D-E / D-R). A CLOSED backend-only set of exactly two values:
 *
 *   - {@see ACTIVE}   — the problem is on the current problem list (default);
 *   - {@see RESOLVED} — the condition has resolved (set once by `ResolveProblem`).
 *
 * The ONLY permitted transition is `ACTIVE → RESOLVED`. `status` is never
 * directly writable — `UpdateProblem` cannot set it and there is no
 * `ReactivateProblem`. `CHRONIC` / `INACTIVE` / `ENTERED_IN_ERROR` and
 * reactivation are deferred (ADR-140 D-E).
 *
 * BACKEND-ONLY (ADR-140 D-R): deliberately a `final class` of string constants,
 * NOT a PHP `enum` — the repo's `config/typescript-transformer.php`
 * `EnumCollector` emits every backed enum under `app/Domains` to
 * `resources/js/shared/types/generated.ts` regardless of `#[TypeScript]`, and
 * ADR-140 D-R requires that file to remain UNCHANGED. This is the ADR-139 D3 /
 * `ClinicalNoteType` discipline (a closed coded string, no auto-generated TS).
 */
final class ProblemStatus
{
    public const ACTIVE = 'ACTIVE';

    public const RESOLVED = 'RESOLVED';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::RESOLVED];

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
                'ProblemStatus must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
