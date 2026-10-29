<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The kind of correction an `AmendProcedure` appends to `clin_procedure_amendments`
 * (13-clinical-procedures-domain.md §81; ADR-146 D-M — the ADR-134
 * `ClinicalNoteAmendmentType` shape, reused for procedures).
 *
 * A backend `final class` constant holder — NOT a PHP enum, NOT `#[TypeScript]`
 * (ADR-146 D-S: no PHASE-6 consumer needs a generated type, unlike
 * `ClinicalNoteAmendmentType` which C.1 already exposed for its own reasons).
 */
final class ProcedureAmendmentType
{
    public const AMENDMENT = 'AMENDMENT';

    public const ADDENDUM = 'ADDENDUM';

    public const CORRECTION = 'CORRECTION';

    /** @var list<string> */
    public const ALL = [
        self::AMENDMENT,
        self::ADDENDUM,
        self::CORRECTION,
    ];

    public static function assert(string $value): string
    {
        $normalized = strtoupper(trim($value));

        if (! in_array($normalized, self::ALL, true)) {
            throw new InvalidArgumentException(sprintf(
                'Invalid ProcedureAmendmentType [%s]. Permitted: %s.',
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
