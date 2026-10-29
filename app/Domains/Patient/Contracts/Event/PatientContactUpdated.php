<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A secondary patient contact point was created, revised or deactivated
 * (10-patient-domain.md §31, §132, §171; ADR-126). One event covers all three
 * — §132 lists a single PatientContactUpdated.
 *
 * PHI-free (IC §7.1.1): the contact value / label are never carried — only ids,
 * the coded channel and whether the point is now active.
 */
final readonly class PatientContactUpdated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $contactId,
        public string $contactType,
        public bool $isActive,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
