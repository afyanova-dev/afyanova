<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\ValueObject;

use InvalidArgumentException;

/**
 * The verification axis of an allergy assertion — how confident the clinical
 * record is that the allergy is real (12-clinical-domain.md §31, §32, §75;
 * ADR-141 D-D). A CLOSED backend-only set of exactly three values:
 *
 *   - {@see SUSPECTED} — reported / recorded, not yet clinically confirmed
 *                        (default at `RecordAllergy`);
 *   - {@see CONFIRMED} — clinically confirmed (via `VerifyAllergy(CONFIRMED)`,
 *                        legal only from `SUSPECTED`);
 *   - {@see REFUTED}   — the assertion was clinically DISPROVEN (allergy testing
 *                        negative; a reaction misattributed as an allergy) —
 *                        via `VerifyAllergy(REFUTED)`, which ATOMICALLY also
 *                        sets `clinical_status = INACTIVE` (the ADR-141 D-D
 *                        biconditional `REFUTED ⇔ INACTIVE`). `REFUTED` is
 *                        terminal — no de-refutation.
 *
 * `verification_status` is never a direct `UpdateAllergy` input; it changes only
 * through the single parameterised `VerifyAllergy` command (ADR-141 D-K / D-D).
 *
 * BACKEND-ONLY (ADR-141 D-D / D-V): a `final class` of string constants, NOT a
 * PHP `enum` — see {@see AllergyStatus} for why (`generated.ts` must remain
 * UNCHANGED).
 */
final class AllergyVerificationStatus
{
    public const SUSPECTED = 'SUSPECTED';

    public const CONFIRMED = 'CONFIRMED';

    public const REFUTED = 'REFUTED';

    /** @var list<string> */
    public const ALL = [self::SUSPECTED, self::CONFIRMED, self::REFUTED];

    /**
     * The values `RecordAllergy` may create with — an allergy is never RECORDED
     * as already `REFUTED` (ADR-141 D-K).
     *
     * @var list<string>
     */
    public const RECORDABLE = [self::SUSPECTED, self::CONFIRMED];

    /**
     * The values `VerifyAllergy` may transition TO (ADR-141 D-K).
     *
     * @var list<string>
     */
    public const VERIFIABLE = [self::CONFIRMED, self::REFUTED];

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
                'AllergyVerificationStatus must be one of %s.',
                implode(' | ', self::ALL),
            ));
        }

        return $value;
    }

    public static function assertRecordable(string $value): string
    {
        if (! in_array($value, self::RECORDABLE, true)) {
            throw new InvalidArgumentException(sprintf(
                'RecordAllergy may only create verification_status %s — not %s.',
                implode(' | ', self::RECORDABLE),
                $value,
            ));
        }

        return $value;
    }

    public static function assertVerifiable(string $value): string
    {
        if (! in_array($value, self::VERIFIABLE, true)) {
            throw new InvalidArgumentException(sprintf(
                'VerifyAllergy may only transition to %s — not %s.',
                implode(' | ', self::VERIFIABLE),
                $value,
            ));
        }

        return $value;
    }
}
