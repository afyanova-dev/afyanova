<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Exception;

use App\Shared\Domain\DomainException;

/**
 * The patient already holds an ACTIVE coverage at this priority
 * (21-insurance-and-payer-domain.md §21, §143; ADR-199 D-A — the partial
 * unique index `(tenant_id, patient_id, priority) WHERE status = 'ACTIVE'`).
 * Terminate the existing one first. Maps to HTTP 409.
 */
final class DuplicateActiveCoverage extends DomainException
{
    public static function forPatientAtPriority(string $patientId, string $priority): self
    {
        return new self(sprintf(
            'Patient [%s] already holds an ACTIVE %s coverage — terminate it first.',
            $patientId,
            $priority,
        ));
    }

    public function errorCode(): string
    {
        return 'duplicate_active_coverage';
    }
}
