<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A patient already has an ARRIVED or ACTIVE encounter at this facility
 * (11-encounter-domain.md Invariant 12b; ADR-121, ADR-130 D). The partial
 * unique index `enc_one_active_per_patient_facility` is the final concurrency
 * guard; IC §9.1 — the handler catches the unique violation and rethrows this
 * DomainException rather than the raw QueryException. A concurrent active
 * encounter is permitted only at a DIFFERENT facility (transfer / referral in
 * progress). Maps to HTTP 409 (§15).
 */
final class ActiveEncounterExists extends DomainException
{
    public static function forPatientAtFacility(string $patientId, string $facilityId): self
    {
        return new self(sprintf(
            'Patient [%s] already has an active encounter at facility [%s] (Invariant 12b).',
            $patientId,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'active_encounter_exists';
    }
}
