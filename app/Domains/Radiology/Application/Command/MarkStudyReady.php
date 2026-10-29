<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a scheduled imaging study as ready for acquisition — patient arrived /
 * prepped (SCHEDULED → READY) (15-radiology-domain.md §104, §111; ADR-166 D-F).
 * Optional — `StartImagingStudy` also accepts a SCHEDULED study. No domain
 * event (an internal operational step — §177 names none).
 */
final readonly class MarkStudyReady implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('MarkStudyReady::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkStudyReady::$actorId must be a UUID (IC §3).');
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
            action: 'radiology.study_ready',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
        );
    }
}
