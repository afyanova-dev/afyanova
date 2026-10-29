<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateReferral` named a `sourceEncounterId` whose encounter does not meet
 * eligibility criteria (ADR-151 D-G). Only checked when a source encounter is
 * given — standalone/CHW referrals have none (§6). Requires encounter status
 * IN {ARRIVED, ACTIVE, COMPLETED} — the `CreateClinicalNote`/ADR-135
 * precedent (a referral decided at discharge is plausible, unlike Theatre's
 * narrower gate). Maps to HTTP 409.
 */
final class EncounterNotEligibleForReferral extends DomainException
{
    public static function status(string $encounterId, string $status): self
    {
        return new self(sprintf(
            'Encounter [%s] is [%s] — referral origination requires ARRIVED, ACTIVE or COMPLETED.',
            $encounterId,
            $status,
        ));
    }

    public function errorCode(): string
    {
        return 'encounter_not_eligible_for_referral';
    }
}
