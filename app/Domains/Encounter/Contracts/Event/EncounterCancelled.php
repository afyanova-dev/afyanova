<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * Domain event: `EncounterCancelled` (11-encounter-domain.md §74, §103, §187,
 * §188; ADR-121: `PLANNED or ARRIVED → CANCELLED`; ADR-130).
 *
 * Emitted when a planned or arrived encounter is cancelled before active care
 * begins (§23). Terminal event. Written to `shared_outbox_events` in the
 * command transaction and relayed asynchronously; no synchronous downstream
 * domain calls.
 *
 * STRICTLY PHI-FREE: carries ONLY system identifiers and event metadata. No
 * patient names, demographics, clinical facts, disposition, cancellation
 * reasons, or location names.
 */
final readonly class EncounterCancelled
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $occurredAt,
        public ?string $correlationId = null,
        public ?string $causationId = null,
    ) {}
}
