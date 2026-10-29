<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Complete a surgical case (17-theatre-domain.md §219; ADR-150 D-D/D-H).
 * `findings`/`outcome`/`complications` are row-only (ADR-136 deny-list),
 * never carried on `SurgicalCaseCompleted`.
 *
 * When `recoveryBedId` is given, the handler calls `BedWardApi::assignBed`
 * (ADR-150 D-H) — NOT a direct dispatch of BedWard's own internal `AssignBed`
 * command, which would cross the Contracts-only module-import boundary
 * (IC §2.1; found by the arch test during implementation, correcting this
 * ADR's own initial "dispatch the command directly" framing) — the caller
 * has already chosen a specific bed, this command does not search for one.
 */
final readonly class CompleteCase implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $actorId,
        public ?string $actualProcedure = null,
        public ?string $findings = null,
        public ?string $outcome = null,
        public ?string $complications = null,
        public ?string $recoveryDestinationType = null,
        public ?string $recoveryBedId = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('CompleteCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteCase::$actorId must be a UUID (IC §3).');
        }

        if ($recoveryBedId !== null && ! Str::isUuid($recoveryBedId)) {
            throw new InvalidArgumentException('CompleteCase::$recoveryBedId must be a UUID or null (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'complete';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.case_completed',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'recovery_destination_type' => $this->recoveryDestinationType,
                'recovery_bed_id' => $this->recoveryBedId,
            ],
        );
    }
}
