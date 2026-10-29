<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One patient's prescriptions (16-pharmacy-domain.md §19, §260; ADR-181 D-F).
 * Optional status filter; deterministic order (recorded_at DESC, id); NO
 * pagination (the ADR-140 D-L precedent). `view` gates on `pharmacy.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Prescription::class)]
final readonly class GetPatientPrescriptions
{
    public function __construct(
        public string $patientId,
        public ?string $status = null,
    ) {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientPrescriptions::$patientId must be a UUID (IC §3).');
        }

        if ($status !== null && ! PrescriptionStatus::isValid(strtoupper(trim($status)))) {
            throw new InvalidArgumentException('GetPatientPrescriptions::$status must be a valid PrescriptionStatus or null.');
        }
    }
}
