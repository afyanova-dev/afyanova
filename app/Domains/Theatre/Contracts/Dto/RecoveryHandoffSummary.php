<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Summary read of one recovery handoff (ADR-156 D-D). A protected read model.
 */
final readonly class RecoveryHandoffSummary
{
    public function __construct(
        public string $recoveryHandoffId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $destinationType,
        public ?string $patientCondition,
        public ?string $pendingActions,
        public ?string $receivingTeamRef,
        public string $status,
        public ?string $acknowledgedByUserId,
        public ?string $acknowledgedAt,
        public string $initiatedByUserId,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
