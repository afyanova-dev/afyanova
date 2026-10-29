<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Contracts\Event;

/**
 * Domain event: `EncounterAbandoned` (11-encounter-domain.md §24, §103, §187,
 * §188; ADR-121: `ARRIVED → LEFT_WITHOUT_BEING_SEEN | ACTIVE → ABANDONED`; ADR-130).
 *
 * Emitted when an arrived or active encounter is abandoned before normal completion.
 * Terminal event. Written to `shared_outbox_events` in the command transaction.
 *
 * STRICTLY PHI-FREE: carries system identifiers, resulting terminal status
 * (`LEFT_WITHOUT_BEING_SEEN` or `ABANDONED`), and event metadata. No patient
 * names, demographics, clinical details, reasons, or notes.
 */
final readonly class EncounterAbandoned
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $encounterId,
        public string $patientId,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
