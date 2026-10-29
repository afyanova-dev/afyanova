<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;

/**
 * 360° Identity Dossier query for the Patient Profile workspace (UI-SPECIFICATIONS.md §11.2.3).
 *
 * Protected (IC §6.2) — mapped to PatientPolicy::view / patient.patients.read.
 */
#[ProtectedQuery(ability: 'view', subjectType: Patient::class)]
final readonly class GetPatientProfile
{
    public function __construct(
        public string $patientId,
        public string $facilityId,
    ) {}
}
