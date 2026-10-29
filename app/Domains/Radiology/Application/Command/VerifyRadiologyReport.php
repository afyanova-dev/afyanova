<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Verify / finalize a radiology report (DRAFT | REVIEWED → VERIFIED)
 * (15-radiology-domain.md §42, §119, §161; ADR-167 D-D). The handler enforces
 * that `findings_text` + `impression_text` are non-blank (§161 — a report
 * cannot be verified empty). Professional-qualification checks (§119) are
 * deferred to HR (PHASE 11).
 */
final readonly class VerifyRadiologyReport implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reportId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('VerifyRadiologyReport::$reportId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('VerifyRadiologyReport::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'verify';
    }

    public function subjectId(): string
    {
        return $this->reportId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.report_verified',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
        );
    }
}
