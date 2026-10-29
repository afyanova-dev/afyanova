<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Complete acquisition of an imaging study (IN_PROGRESS → COMPLETED)
 * (15-radiology-domain.md §62, §104, §165; ADR-166 D-F). Emits
 * `ImagingStudyCompleted` — the "a billable imaging examination occurred"
 * signal (§62).
 */
final readonly class CompleteImagingStudy implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('CompleteImagingStudy::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CompleteImagingStudy::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'perform';
    }

    public function subjectId(): string
    {
        return $this->studyId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.study_completed',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
        );
    }
}
