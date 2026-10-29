<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * A care-team member's role on an encounter (12-clinical-domain.md §4.1;
 * ADR-142 D-F). An OPEN coded string, set-validated by the write path against
 * the documented constant list below:
 *
 *   ATTENDING | CONSULTANT | RESIDENT | NURSE | ALLIED_HEALTH | COORDINATOR
 *
 * BEHAVIOUR-NEUTRAL: nothing in C.6 branches on `team_role` — in particular,
 * provider-of-record is an EXPLICIT boolean flag (D-G) and is NEVER derived
 * from `team_role = ATTENDING`.
 *
 * Adding a new role is a code-level decision plus a governance / ADR note — it
 * does NOT require a database migration merely to add a vocabulary value
 * (ADR-142 D-F).
 *
 * BACKEND-ONLY (ADR-142 D-F): deliberately a `final class` of string constants,
 * NOT a PHP `enum` — the repo's `config/typescript-transformer.php`
 * `EnumCollector` emits every backed enum under `app/Domains` to
 * `resources/js/shared/types/generated.ts` regardless of `#[TypeScript]`, and
 * ADR-142 requires that file to remain UNCHANGED. This is the `ProblemStatus` /
 * `ClinicalNoteType` discipline (a closed coded string, no auto-generated TS,
 * no DB CHECK constraint).
 */
final class TeamRole
{
    public const ATTENDING = 'ATTENDING';

    public const CONSULTANT = 'CONSULTANT';

    public const RESIDENT = 'RESIDENT';

    public const NURSE = 'NURSE';

    public const ALLIED_HEALTH = 'ALLIED_HEALTH';

    public const COORDINATOR = 'COORDINATOR';

    /** @var list<string> */
    public const ALL = [
        self::ATTENDING,
        self::CONSULTANT,
        self::RESIDENT,
        self::NURSE,
        self::ALLIED_HEALTH,
        self::COORDINATOR,
    ];

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

    /**
     * Set-membership validation only (ADR-142 D-F) — the trimmed value must be
     * one of {@see ALL}. Anything else is an `InvalidArgumentException` (the
     * `ProblemStatus` / `AllergyVerificationStatus` discipline).
     */
    public static function assert(string $value): string
    {
        $value = trim($value);

        if (! self::isValid($value)) {
            throw new InvalidArgumentException(sprintf(
                'TeamRole must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }
}
