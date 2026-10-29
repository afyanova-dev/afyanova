<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel a scheduled imaging study before acquisition (SCHEDULED | READY →
 * CANCELLED) (15-radiology-domain.md §55, §104; ADR-166 D-F). `$reasonCode` is
 * open-coded (normalised uppercase in the handler — §55 seeds); `$detail` is
 * free text, row-only + on the audit row, never in the event (ADR-136).
 */
final readonly class CancelImagingStudy implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $studyId,
        public string $actorId,
        public string $reasonCode,
        public ?string $detail = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($studyId)) {
            throw new InvalidArgumentException('CancelImagingStudy::$studyId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelImagingStudy::$actorId must be a UUID (IC §3).');
        }

        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('CancelImagingStudy::$reasonCode must not be blank (§55).');
        }

        if ($detail !== null && trim($detail) === '') {
            throw new InvalidArgumentException('CancelImagingStudy::$detail must be a non-blank string or null.');
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
            action: 'radiology.study_cancelled',
            actorId: $this->actorId,
            targetType: 'imaging_study',
            targetId: $this->studyId,
            metadata: ['reason_code' => strtoupper(trim($this->reasonCode))],
            reason: ($this->detail !== null && trim($this->detail) !== '') ? trim($this->detail) : null,
        );
    }
}
