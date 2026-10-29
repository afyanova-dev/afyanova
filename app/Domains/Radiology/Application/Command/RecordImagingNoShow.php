<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record that the patient failed to attend a scheduled imaging study
 * (SCHEDULED | READY → NO_SHOW) (15-radiology-domain.md §57, §104; ADR-166 D-F).
 * A no-show is distinct from a cancellation (Invariant 13). No domain event
 * (§177 names none — a NO_SHOW event is additive when a consumer needs it).
 */
final readonly class RecordImagingNoShow implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('RecordImagingNoShow::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordImagingNoShow::$actorId must be a UUID (IC §3).');
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
            action: 'radiology.study_no_show',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
        );
    }
}
