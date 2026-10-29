<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient's core demographics were changed via UpdatePatient
 * (10-patient-domain.md §26, §60, §132; ADR-127). Also the ONLY signal that
 * `is_provisional` was cleared (ADR-119 — no separate event).
 *
 * PHI-free (IC §7.1.1): `changedFields` is a list of coded column names only
 * (e.g. ["family_name", "date_of_birth"]) — it never carries the values, and
 * no name / DOB / phone / email appears anywhere on this event.
 */
final readonly class PatientUpdated
{
    /**
     * @param  list<string>  $changedFields  coded column names that changed
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public array $changedFields,
        public bool $provisionalCleared,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
