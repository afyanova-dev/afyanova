<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Release a verified radiology report to its recipients (VERIFIED → RELEASED)
 * (15-radiology-domain.md §51–§52, §166; ADR-167 D-D). Via
 * `RadiologyReportReleaseGate`. Release != clinical acknowledgement (§166).
 */
final readonly class ReleaseRadiologyReport implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reportId,
        public string $actorId,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('ReleaseRadiologyReport::$reportId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('ReleaseRadiologyReport::$actorId must be a UUID (IC §3).');
        }
    }

    public function policyAbility(): string
    {
        return 'release';
    }

    public function subjectId(): string
    {
        return $this->reportId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.report_released',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
        );
    }
}
