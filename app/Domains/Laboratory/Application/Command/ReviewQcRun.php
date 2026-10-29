<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Review a failed / warning QC run (14-laboratory-domain.md §83, §159;
 * ADR-163 D-F). Records the review and LIFTS the `QC_FAILED` release hold from
 * the results the failed run had held. `$note` is free text, row-only + audit.
 */
final readonly class ReviewQcRun implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $qcRunId,
        public string $actorId,
        public ?string $note = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($qcRunId)) {
            throw new InvalidArgumentException('ReviewQcRun::$qcRunId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReviewQcRun::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'review';
    }

    public function subjectId(): string
    {
        return $this->qcRunId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'laboratory.qc_run_reviewed',
            actorId: $this->actorId,
            targetType: 'lab_qc_run',
            targetId: $this->qcRunId,
            reason: ($this->note !== null && trim($this->note) !== '') ? trim($this->note) : null,
        );
    }
}
