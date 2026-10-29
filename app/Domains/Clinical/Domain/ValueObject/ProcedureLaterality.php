<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The body-side qualifier a procedure may require (13-clinical-procedures-domain.md
 * §48; ADR-146 D-H).
 *
 * A CLOSED coded set — deliberately closed (unlike `site`, which is open free
 * text) because wrong-laterality is a never-event safety concern (13 §46
 * "correct site"), even though CP.1 has no scoring / checklist engine around it.
 * Backend-only string-constant holder (NOT a PHP enum, NOT `#[TypeScript]` —
 * ADR-146 D-S: no PHASE-6 consumer needs a generated type; the ClinicalPlanType /
 * TriagePriority precedent). Required only when the referenced
 * `ProcedureDefinition::requires_laterality` is true; validated at
 * `StartProcedure`. Behaviour-neutral — nothing branches lifecycle,
 * authorization or validation off the specific value.
 */
final class ProcedureLaterality
{
    public const LEFT = 'LEFT';

    public const RIGHT = 'RIGHT';

    public const BILATERAL = 'BILATERAL';

    public const NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const UNKNOWN = 'UNKNOWN';

    /** @var list<string> */
    public const ALL = [
        self::LEFT,
        self::RIGHT,
        self::BILATERAL,
        self::NOT_APPLICABLE,
        self::UNKNOWN,
    ];

    public static function assert(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ProcedureLaterality [%s]. Permitted: %s.',
                $value,
                implode(', ', self::ALL),
            ));
        }

        return $normalized;
    }

    public static function isValid(string $value): bool
    {
        return in_array(strtoupper(trim($value)), self::ALL, true);
    }
}
