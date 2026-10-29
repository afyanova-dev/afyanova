<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateImagingOrder` named a `patientId` that is not the patient of the
 * `encounterId` it also named (ADR-165 D-J — the Lab.1 `LabOrderContextMismatch`
 * precedent). The encounter is authoritative for the patient link. Maps to
 * HTTP 409.
 */
final class ImagingOrderContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateImagingOrder named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'imaging_order_context_mismatch';
    }
}
