<?php

declare(strict_types=1);

namespace App\Domains\Patient\Application\Query;

use App\Domains\Patient\Domain\Model\Patient;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;

/**
 * Backs PatientApi::getPatientSummaries (ADR-125; Increment B).
 * Protected (IC §6.2) — patient identity/demographics are sensitive
 * (10-patient-domain.md Invariant 12), unlike a bare existence check.
 *
 * The `#[ProtectedQuery]` gate enforces coarse tenant + `patient.patients.read`
 * (`PatientPolicy::view`).
 *
 * Constructor validates, filters to UUID strings, and deduplicates IDs.
 */
#[ProtectedQuery(ability: 'view', subjectType: Patient::class)]
final readonly class GetPatientSummaries
{
    /** @var list<string> */
    public array $patientIds;

    /**
     * @param  array<mixed>  $patientIds
     */
    public function __construct(array $patientIds)
    {
        $valid = [];
        foreach ($patientIds as $id) {
            if (is_string($id) && Str::isUuid($id)) {
                $valid[$id] = true;
            }
        }

        $this->patientIds = array_keys($valid);
    }
}
