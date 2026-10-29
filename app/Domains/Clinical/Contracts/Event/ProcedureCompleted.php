<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A procedure's execution completed (13-clinical-procedures-domain.md §33,
 * §56, §60, §117, §120–§126; ADR-137, ADR-146 D-R). Written to
 * `shared_outbox_events` in the `CompleteProcedure` transaction; idempotent
 * consumers key on `eventId`. NOT broadcast.
 *
 * The ONE CP.1 event with a genuinely anticipated first consumer: Billing
 * (PHASE 10) decides the charge from this fact (13 §56 — Clinical Procedures
 * creates no invoice); Inventory has its own signal
 * ({@see ProcedureMaterialsConsumed}). Neither subscribes in PHASE 6 (the
 * ADR-137 / ADR-143 event-only-to-an-unbuilt-domain precedent) — CP.1 builds no
 * consumer code.
 *
 * STRICTLY PHI-FREE (ADR-136; ADR-146 D-R) — identifiers + `definitionId` +
 * coded `procedureCategory` + the resulting coded `status` + the coded
 * `outcome` (ADR-136 explicitly allow-lists `outcome`; 13 §60/§117 tie it to
 * billing variance) + envelope `occurredAt` ONLY. NO `laterality` (R-3 —
 * Billing queries back for it), NO `outcome_detail` / `complications` / free
 * text.
 */
final readonly class ProcedureCompleted
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
        public ?string $outcome,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
