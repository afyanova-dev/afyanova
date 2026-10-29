<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * One or more materials were consumed during / after a procedure
 * (13-clinical-procedures-domain.md §50–§56; ADR-137, ADR-146 D-N). Written to
 * `shared_outbox_events` in the `RecordProcedureMaterials` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast.
 *
 * The Inventory (PHASE 9) signal — Inventory subscribes in its own increment to
 * decrement stock (13 §50, ADR-137: Clinical Procedures records the fact of
 * consumption; Inventory owns the stock movement). CP.1 builds no consumer
 * code.
 *
 * STRICTLY PHI-FREE (ADR-136; ADR-146 D-N) — identifiers + `definitionId` +
 * the coded material lines (ref / quantity / unit / batch — reference data, not
 * clinical detail) + envelope `occurredAt` ONLY. NO stock balances.
 */
final readonly class ProcedureMaterialsConsumed
{
    /**
     * @param  list<array{materialRef: string, quantity: float, unit: ?string, batchRef: ?string}>  $materials
     */
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $procedureId,
        public string $definitionId,
        public array $materials,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
