<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateEncounter` was attempted for a patient whose lifecycle status is not
 * ACTIVE (11-encounter-domain.md §9, §45; ADR-116, ADR-128, ADR-130 E). A new
 * attendance is created only for an ACTIVE identity:
 *
 *   - MERGED   — the identity is consolidated; use the surviving record (ADR-128);
 *   - DECEASED — there is no new attendance for a deceased patient (§45);
 *   - INACTIVE — reactivate the patient first (ADR-116; `CreateEncounter` must
 *                not silently resurrect a deliberately deactivated identity).
 *
 * A provisional ACTIVE patient IS eligible — that is the ADR-119 emergency
 * flow. Maps to HTTP 409 (§15).
 */
final class PatientNotEligibleForEncounter extends DomainException
{
    public static function merged(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is MERGED — create the encounter against the surviving record.',
            $patientId,
        ));
    }

    public static function deceased(string $patientId): self
    {
        return new self(sprintf('Patient [%s] is DECEASED — a new encounter cannot be created (§45).', $patientId));
    }

    public static function inactive(string $patientId): self
    {
        return new self(sprintf(
            'Patient [%s] is INACTIVE — reactivate the patient before creating an encounter.',
            $patientId,
        ));
    }

    public function errorCode(): string
    {
        return 'patient_not_eligible_for_encounter';
    }
}
