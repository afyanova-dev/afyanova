<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `CreateLabOrder` named a `patientId` that is not the patient of the
 * `encounterId` it also named (ADR-157 D-I — the `ReferralContextMismatch`
 * precedent). The encounter is authoritative for the patient link. Maps to
 * HTTP 409.
 */
final class LabOrderContextMismatch extends DomainException
{
    public static function patientEncounter(string $commandPatientId, string $encounterId, string $encounterPatientId): self
    {
        return new self(sprintf(
            'CreateLabOrder named patient [%s] but encounter [%s] belongs to patient [%s].',
            $commandPatientId,
            $encounterId,
            $encounterPatientId,
        ));
    }

    public function errorCode(): string
    {
        return 'lab_order_context_mismatch';
    }
}
