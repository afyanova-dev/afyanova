<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * `RegisterMemberCoverage` named a patient that does not exist in the current
 * tenant scope (ADR-199 D-E — `PatientApi::patientExists` fail-closed; the
 * Insurance-domain twin of `BillingPatientNotFound`). Maps to HTTP 404.
 */
final class InsurancePatientNotFound extends DomainException
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
