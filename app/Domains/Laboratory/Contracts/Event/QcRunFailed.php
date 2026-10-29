<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory QC run FAILED (14-laboratory-domain.md §85, §138, §159;
 * ADR-163 D-H) — affected patient results are held from release. PHI-safe:
 * identifiers only.
 */
final readonly class QcRunFailed
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $qcRunId,
        public string $analyzerRef,
        public ?string $testDefinitionId,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
