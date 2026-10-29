<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a planned procedure for a surgical case (17-theatre-domain.md §10;
 * ADR-156 D-C). `$procedureId` is client-minted. `facility_id` / `patient_id`
 * / `encounter_id` and the `sequence` are set by the handler from the case.
 * `$plannedDescription` is ROW-ONLY (ADR-136 deny-list).
 */
final readonly class RecordPlannedProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $caseId,
        public string $actorId,
        public ?string $plannedDescription = null,
        public ?string $primarySurgeonUserId = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['procedureId' => $procedureId, 'caseId' => $caseId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordPlannedProcedure::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($primarySurgeonUserId !== null && ! Str::isUuid($primarySurgeonUserId)) {
            throw new InvalidArgumentException('RecordPlannedProcedure::$primarySurgeonUserId must be a UUID or null (IC §3).');
        }

        if ($plannedDescription !== null && trim($plannedDescription) === '') {
            throw new InvalidArgumentException('RecordPlannedProcedure::$plannedDescription must be a non-blank string or null.');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.surgical_procedure_recorded',
            actorId: $this->actorId,
            targetType: 'surgical_procedure',
            targetId: $this->procedureId,
            metadata: ['case_id' => $this->caseId],
        );
    }
}
