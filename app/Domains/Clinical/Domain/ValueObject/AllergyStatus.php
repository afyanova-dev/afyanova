<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The clinical-lifecycle axis of an allergy assertion (12-clinical-domain.md
 * §31, §75; ADR-141 D-D). A CLOSED backend-only set of exactly three values:
 *
 *   - {@see ACTIVE}   — the allergy is on the current safety list (default);
 *   - {@see RESOLVED} — the allergy was clinically real / relevant but is no
 *                       longer active (outgrown; desensitised) — set once by
 *                       `ResolveAllergy`;
 *   - {@see INACTIVE} — the assertion was clinically DISPROVEN — reached ONLY
 *                       and ALWAYS together with `verification_status = REFUTED`
 *                       (the ADR-141 D-D biconditional), set once by
 *                       `VerifyAllergy(REFUTED)`.
 *
 * `status` is never a direct command input. The ONLY transitions are
 * `ACTIVE → RESOLVED` (via `ResolveAllergy`) and `{ACTIVE|RESOLVED} → INACTIVE`
 * (via `VerifyAllergy(REFUTED)`, atomically with `verification_status`). There
 * is NO `InactivateAllergy`, NO reactivation; `RESOLVED` and `INACTIVE` are
 * terminal (ADR-141 D-D).
 *
 * BACKEND-ONLY (ADR-141 D-D / D-V): a `final class` of string constants, NOT a
 * PHP `enum` — the repo's `config/typescript-transformer.php` `EnumCollector`
 * emits every backed enum under `app/Domains` to
 * `resources/js/shared/types/generated.ts` regardless of `#[TypeScript]`, and
 * ADR-141 requires that file to remain UNCHANGED (the `ProblemStatus` /
 * `ProblemChangeType` discipline).
 */
final class AllergyStatus
{
    public const ACTIVE = 'ACTIVE';

    public const RESOLVED = 'RESOLVED';

    public const INACTIVE = 'INACTIVE';

    /** @var list<string> */
    public const ALL = [self::ACTIVE, self::RESOLVED, self::INACTIVE];

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
                'AllergyStatus must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
