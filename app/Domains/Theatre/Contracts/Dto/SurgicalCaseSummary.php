<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Minimal summary read of one surgical case (ADR-150 D-J). A protected read
 * model, not an event payload — the ADR-136 deny-list applies to events/
 * audit, not to a gated query result for an authorized caller.
 */
final readonly class SurgicalCaseSummary
{
    /**
     * @param  list<array{stage: string, completed_at: string, actor_user_id: string}>  $safetyChecklist
     */
    public function __construct(
        public string $caseId,
        public string $facilityId,
        public string $patientId,
        public string $encounterId,
        public ?string $operatingRoomId,
        public string $caseNumber,
        public string $priority,
        public ?string $scheduledFor,
        public ?string $plannedProcedure,
        public ?string $actualProcedure,
        public ?string $indication,
        public ?string $findings,
        public ?string $outcome,
        public ?string $complications,
        public array $safetyChecklist,
        public ?string $recoveryDestinationType,
        public string $status,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
