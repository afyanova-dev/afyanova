<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * Minimal, synchronous, display-only cross-domain read of a patient identity
 * (ADR-125). NOT an event payload — never reuse this as a domain event
 * contract; events carry only identifiers and coded values (IC §7.1.1).
 *
 * `dateOfBirth` is present only when the caller was authorized to call
 * GetPatientSummary in the first place — the #[ProtectedQuery] gate on that
 * query IS the authorization check (ADR-125), so there is no further
 * field-level condition here.
 */
final readonly class PatientSummary
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public ?string $dateOfBirth,
        public string $sex,
        public string $status,
        public bool $isProvisional,
    ) {}
}
