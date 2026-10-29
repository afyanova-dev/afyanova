<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record one completed safety-checklist entry (17-theatre-domain.md §50-51;
 * ADR-150 D-G). `stage` is open-coded — content is governance-configurable,
 * never a hardcoded external standard (ADR-150's own corrected "WHO
 * checklist" framing).
 */
final readonly class CompleteChecklistStage implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $caseId,
        public string $stage,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('CompleteChecklistStage::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteChecklistStage::$actorId must be a UUID (IC §3).');
        }

        if (trim($stage) === '') {
            throw new InvalidArgumentException('CompleteChecklistStage::$stage must not be blank.');
        }
    }

    public function policyAbility(): string
    {
        return 'completeChecklistStage';
    }

    public function subjectId(): string
    {
        return $this->caseId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'theatre.checklist_stage_completed',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'stage' => $this->stage,
            ],
        );
    }
}
