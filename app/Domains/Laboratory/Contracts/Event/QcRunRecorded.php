<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Event;

/**
 * A laboratory QC run was recorded (14-laboratory-domain.md §83, §138;
 * ADR-163 D-H). PHI-safe: identifiers + the coded `status` only.
 */
final readonly class QcRunRecorded
{
    public function __construct(
        public string $eventId,
        public string $tenantId,
        public string $facilityId,
        public string $qcRunId,
        public string $analyzerRef,
        public ?string $testDefinitionId,
        public string $status,
        public string $occurredAt,
        public string $correlationId,
        public ?string $causationId = null,
    ) {}
}
