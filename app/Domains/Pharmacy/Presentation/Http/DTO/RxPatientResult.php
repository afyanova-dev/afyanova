<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One patient hit on the transcribe-patient search — the full MPI result
 * shape so the page renders the shared PatientResolution kit 1:1.
 */
#[TypeScript]
final readonly class RxPatientResult
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public string $sex,
        public string $status,
        public bool $isProvisional,
        public bool $hasAccessibleFacilityRegistration,
        public ?string $survivingPatientId,
    ) {}
}
