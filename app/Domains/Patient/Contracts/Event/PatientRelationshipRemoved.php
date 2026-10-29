<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A patient relationship record was deactivated (ADR-126 D-8 — deactivation,
 * not hard delete; 10-patient-domain.md §132). PHI-free (IC §7.1.1): ids and
 * the coded role only.
 */
final readonly class PatientRelationshipRemoved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $patientId,
        public string $relationshipId,
        public string $role,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
