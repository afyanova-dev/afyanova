<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Event;

/**
 * A relationship (emergency contact / next of kin / guardian / …) was recorded
 * for a patient (10-patient-domain.md §35-§38, §132; ADR-126). PHI-free
 * (IC §7.1.1): the related person's name / phone are never carried — only ids
 * and the coded role.
 */
final readonly class PatientRelationshipRecorded
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
