<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A lifecycle / verification transition was attempted against an allergy it
 * cannot legally make (12-clinical-domain.md §31, §74–§75; ADR-141 D-D / D-K —
 * the `IllegalProblemStatusTransition` precedent). Validated in the `Allergy`
 * aggregate BEFORE any mutation. Maps to HTTP 409 (IC §15).
 *
 *   - `resolve`          — `ResolveAllergy` is legal only from
 *                          `clinical_status = ACTIVE`. A `RESOLVED` or
 *                          `INACTIVE` allergy is not re-resolved (not
 *                          idempotent) and there is no `UnresolveAllergy`.
 *   - `confirmVerification` — `VerifyAllergy(CONFIRMED)` is legal only from
 *                          `verification_status = SUSPECTED`.
 *   - `refuteVerification`  — `VerifyAllergy(REFUTED)` is illegal only when the
 *                          allergy is already `REFUTED` (`REFUTED` is terminal —
 *                          no de-refutation). It is legal from `SUSPECTED` or
 *                          `CONFIRMED`, and atomically drives `clinical_status`
 *                          to `INACTIVE` (the ADR-141 D-D biconditional).
 */
final class IllegalAllergyStatusTransition extends DomainException
{
    public static function resolve(string $currentClinicalStatus): self
    {
        return new self(sprintf(
            'ResolveAllergy is not a legal transition from clinical_status %s. Resolution requires ACTIVE.',
            $currentClinicalStatus,
        ));
    }

    public static function confirmVerification(string $currentVerificationStatus): self
    {
        return new self(sprintf(
            'VerifyAllergy(CONFIRMED) is not a legal transition from verification_status %s. '.
            'Confirmation requires SUSPECTED.',
            $currentVerificationStatus,
        ));
    }

    public static function refuteVerification(string $currentVerificationStatus): self
    {
        return new self(sprintf(
            'VerifyAllergy(REFUTED) is not a legal transition from verification_status %s. '.
            'REFUTED is terminal — there is no de-refutation.',
            $currentVerificationStatus,
        ));
    }

    public function errorCode(): string
    {
        return 'illegal_allergy_status_transition';
    }
}
