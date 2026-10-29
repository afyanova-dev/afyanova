<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A longitudinal problem's clinical content was revised or corrected in place
 * (12-clinical-domain.md §79, §165; ADR-136, ADR-140 D-M). The problem keeps
 * its stable identity; the pre-change state is preserved in
 * `clin_problem_history`. Written to `shared_outbox_events` in the
 * `UpdateProblem` transaction and relayed asynchronously; idempotent consumers
 * key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-140 D-M): identifiers plus the
 * coded `changeType` (`REVISION` | `CORRECTION`) and the envelope `occurredAt`.
 * NO description, NO code, NO onset date, NO free-text correction / revision
 * `reason`, NO previous-state values, NO demographics.
 */
final readonly class ProblemUpdated
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $problemId,
        public string $changeType,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
