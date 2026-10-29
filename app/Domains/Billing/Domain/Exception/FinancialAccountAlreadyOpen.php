<?php

declare(strict_types=1);

namespace App\Domains\Billing\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * A non-closed Financial Account already exists for this patient at this
 * facility (20-billing-and-payments-domain.md §7 / §145 / §149; ADR-187 D-B —
 * one non-closed account per patient per facility, the partial unique index).
 * Maps to HTTP 409 — the caller should read the existing account instead.
 */
final class FinancialAccountAlreadyOpen extends DomainException
{
    public static function forPatientAtFacility(string $patientId, string $facilityId): self
    {
        return new self(sprintf(
            'A non-closed Financial Account already exists for patient [%s] at facility [%s].',
            $patientId,
            $facilityId,
        ));
    }

    public function errorCode(): string
    {
        return 'financial_account_already_open';
    }
}
