<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * An authorized procedure was marked ready for execution
 * (13-clinical-procedures-domain.md §28, §120–§123; ADR-137, ADR-146 D-F / D-R).
 * Written to `shared_outbox_events` in the `MarkProcedureReady` transaction;
 * idempotent consumers key on `eventId`. NOT broadcast. CONSUMER-LESS in
 * PHASE 6.
 *
 * Fires ONLY when the procedure's definition requires a readiness check
 * (ADR-146 D-F) — a consumer MUST NOT assume this event precedes
 * `ProcedureStarted` (a fast-path procedure goes `AUTHORIZED → IN_PROGRESS`
 * with no `ProcedureReady`).
 *
 * STRICTLY PHI-FREE (ADR-136; ADR-146 D-R) — identifiers + `definitionId` +
 * coded `procedureCategory` + the resulting coded `status` + envelope
 * `occurredAt` ONLY.
 */
final readonly class ProcedureReady
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
