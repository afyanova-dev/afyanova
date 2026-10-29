<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Mark a DRAFT radiology report as REVIEWED (15-radiology-domain.md §42, §103;
 * ADR-167 D-D). Optional — `VerifyRadiologyReport` also accepts a DRAFT report.
 * No domain event.
 */
final readonly class MarkReportReviewed implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reportId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('MarkReportReviewed::$reportId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('MarkReportReviewed::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'author';
    }

    public function subjectId(): string
    {
        return $this->reportId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.report_reviewed',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
        );
    }
}
