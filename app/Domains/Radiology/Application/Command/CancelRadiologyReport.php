<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\ReportAmendmentIncomplete;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cancel a non-released radiology report ({DRAFT | REVIEWED | VERIFIED} →
 * CANCELLED) (15-radiology-domain.md §164; ADR-168 D-D). A RELEASED report is
 * corrected via amendment, never cancelled. `$reason` is required, row-only +
 * audit, never in the event (ADR-136).
 */
final readonly class CancelRadiologyReport implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reportId,
        public string $actorId,
        public string $reason,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('CancelRadiologyReport::$reportId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CancelRadiologyReport::$actorId must be a UUID (IC §3).');
        }

        if (trim($reason) === '') {
            throw ReportAmendmentIncomplete::missingReason();
        }
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->reportId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.report_cancelled',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
            reason: trim($this->reason),
        );
    }
}
