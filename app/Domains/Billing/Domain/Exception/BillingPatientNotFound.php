<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `OpenFinancialAccount` named a patient that does not exist in the current
 * tenant scope (checked via `PatientApi::patientExists`; ADR-187 D-D — fail
 * closed, the `EncounterApi::encounterExists` precedent). Maps to HTTP 404.
 */
final class BillingPatientNotFound extends DomainException
{
    public static function id(string $patientId): self
    {
        return new self(sprintf('Patient [%s] not found.', $patientId));
    }

    public function errorCode(): string
    {
        return 'not_found';
    }

    public function httpStatus(): int
    {
        return 404;
    }
}
