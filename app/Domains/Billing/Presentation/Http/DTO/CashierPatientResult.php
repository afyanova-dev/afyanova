<?php

declare(strict_types=1);

namespace App\Domains\Billing\Presentation\Http\DTO;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One patient hit on the cashier find-account search — the full MPI result
 * shape (Patient owns the truth; this DTO carries it through so the page can
 * render the shared PatientResolution kit 1:1 with Reception).
 */
#[TypeScript]
final readonly class CashierPatientResult
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
