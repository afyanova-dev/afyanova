<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A longitudinal problem was added to a patient's problem list
 * (12-clinical-domain.md §79, §165; ADR-136, ADR-140 D-M). Written to
 * `shared_outbox_events` in the `AddProblem` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-140 D-M — the problem `code`, the
 * free-text `description`, `onset_date` and any correction rationale are
 * clinical detail, deny-listed): identifiers plus the coded `problemCodeSystem`
 * (nullable / absent when the problem carries no code) and the envelope
 * `occurredAt`. NO problem code, NO description, NO onset date, NO
 * `sourceDiagnosisId`, NO demographics.
 */
final readonly class ProblemAdded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $problemId,
        public ?string $problemCodeSystem,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
