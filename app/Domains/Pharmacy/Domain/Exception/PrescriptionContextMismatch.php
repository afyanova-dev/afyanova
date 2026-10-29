<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `AcceptPrescription` fetched the prescribing decision but its patient does not
 * match the prescription stub the listener created (16-pharmacy-domain.md §19;
 * ADR-181 D-E). Maps to HTTP 409.
 */
final class PrescriptionContextMismatch extends DomainException
{
    public static function patient(string $prescriptionId, string $stubPatientId, string $decisionPatientId): self
    {
        return new self(sprintf(
            'Prescription [%s] is for patient [%s] but the prescribing decision names patient [%s].',
            $prescriptionId,
            $stubPatientId,
            $decisionPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'prescription_context_mismatch';
    }
}
