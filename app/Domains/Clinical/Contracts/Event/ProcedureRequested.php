<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A procedure was requested against an encounter (13-clinical-procedures-domain.md
 * §120–§123; ADR-137, ADR-146 D-R). Written to `shared_outbox_events` in the
 * `RequestProcedure` transaction and relayed asynchronously; idempotent
 * consumers key on `eventId`. NOT broadcast.
 *
 * CONSUMER-LESS in PHASE 6 — Billing (PHASE 10) / Inventory (PHASE 9) /
 * Scheduling (PHASE 11) each subscribe in their own increment (the ADR-137 /
 * ADR-143 event-only-to-an-unbuilt-domain precedent).
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136 allow-list; ADR-146 D-R): identifiers +
 * `definitionId` + the coded `procedureCategory` + the resulting coded `status`
 * + the envelope `occurredAt` ONLY. NO `procedure_number` (a tenant-sequential
 * number leaks volume — R-2), NO site / indication / free text.
 */
final readonly class ProcedureRequested
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
