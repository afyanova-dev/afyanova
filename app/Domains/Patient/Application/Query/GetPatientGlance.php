<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;

/**
 * Rich identity-dossier preview for the Patient Glance console (verified
 * identifiers, facility reach, surviving-patient link).
 *
 * Protected (IC §6.2) — patient identity/demographics are sensitive
 * (10-patient-domain.md Invariant 12); the same coarse gate as
 * GetPatientSummary (PatientPolicy::view / patient.patients.read).
 */
#[ProtectedQuery(ability: 'view', subjectType: Patient::class)]
final readonly class GetPatientGlance
{
    public function __construct(
        public string $patientId,
        public string $facilityId,
    ) {}
}
