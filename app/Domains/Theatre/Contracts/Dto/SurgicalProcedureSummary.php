<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Summary read of one surgical procedure (ADR-156 D-C). A protected read
 * model, not an event payload.
 */
final readonly class SurgicalProcedureSummary
{
    public function __construct(
        public string $surgicalProcedureId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public int $sequence,
        public ?string $plannedDescription,
        public ?string $actualDescription,
        public ?string $startedAt,
        public ?string $endedAt,
        public ?string $primarySurgeonUserId,
        public ?string $outcome,
        public ?string $findings,
        public ?string $complications,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
