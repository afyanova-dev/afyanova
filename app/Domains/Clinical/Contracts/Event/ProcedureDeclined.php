<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A patient declined a procedure (13-clinical-procedures-domain.md §37,
 * §120–§123; ADR-137, ADR-146 D-F / D-R). Written to `shared_outbox_events` in
 * the `DeclineProcedure` transaction; idempotent consumers key on `eventId`.
 * NOT broadcast. CONSUMER-LESS in PHASE 6.
 *
 * Fires from `AUTHORIZED` or `READY` (ADR-146 D-F — an ADR-137 state-machine
 * refinement made necessary by `READY` being definition-conditional).
 *
 * STRICTLY PHI-FREE (ADR-136; ADR-146 D-R) — identifiers + `definitionId` +
 * coded `procedureCategory` + the resulting coded `status` + envelope
 * `occurredAt` ONLY. NO `status_reason` (on the row / D-J — never an event
 * field).
 */
final readonly class ProcedureDeclined
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $procedureId,
        public string $definitionId,
        public ?string $procedureCategory,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
