<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;

/**
 * Backs PatientApi::getPatientSummary (ADR-125). Protected (IC §6.2) — patient
 * identity/demographics are sensitive (10-patient-domain.md Invariant 12),
 * unlike a bare existence check or reference-catalog read.
 */
#[ProtectedQuery(ability: 'view', subjectType: Patient::class)]
final readonly class GetPatientSummary
{
    public function __construct(public string $patientId) {}
}
