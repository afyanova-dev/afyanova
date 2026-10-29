<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Event;

/**
 * A longitudinal problem was resolved (`ACTIVE → RESOLVED`)
 * (12-clinical-domain.md §79, §165; ADR-136, ADR-140 D-M). The problem keeps
 * its stable identity; `resolved_at` is set once. Written to
 * `shared_outbox_events` in the `ResolveProblem` transaction and relayed
 * asynchronously; idempotent consumers key on `eventId`. NOT broadcast.
 *
 * STRICTLY PHI-FREE (IC §7.1.1; ADR-136; ADR-140 D-M): identifiers plus the
 * envelope `occurredAt` and the coded lifecycle timestamp `resolvedAt`. NO
 * description, NO code, NO onset date, NO free text, NO demographics.
 */
final readonly class ProblemResolved
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public string $problemId,
        public string $resolvedAt,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId,
    ) {}
}
