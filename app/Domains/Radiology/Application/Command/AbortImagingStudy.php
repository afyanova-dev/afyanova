<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record that an in-progress imaging study failed to complete (IN_PROGRESS →
 * ABORTED) (15-radiology-domain.md §56, §104; ADR-166 D-F). `$reasonCode` is
 * open-coded (§56 seeds); `$detail` is required (a non-blank reason must be
 * recorded — §56) and is free text, row-only + on the audit row, never in the
 * event (ADR-136).
 */
final readonly class AbortImagingStudy implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public string $reasonCode,
        public string $detail,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('AbortImagingStudy::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AbortImagingStudy::$actorId must be a UUID (IC §3).');
        }

        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('AbortImagingStudy::$reasonCode must not be blank (§56).');
        }

        if (trim($detail) === '') {
            throw new InvalidArgumentException('AbortImagingStudy::$detail must not be blank — a reason must be recorded (§56).');
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
            action: 'radiology.study_aborted',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
            metadata: ['reason_code' => strtoupper(trim($this->reasonCode))],
            reason: trim($this->detail),
        );
    }
}
