<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Record a critical imaging finding against a finalized radiology report
 * (15-radiology-domain.md §46–§47, §101, §167; ADR-169 D-E). Multiple per
 * report. The report must be VERIFIED | RELEASED | AMENDED.
 *
 * `$criticalFindingId` is client-minted (UUIDv7). `$findingSummary` is free
 * text, ROW-ONLY (ADR-136). `$severity` is open-coded (normalised uppercase in
 * the handler).
 */
final readonly class RecordCriticalFinding implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $criticalFindingId,
        public string $reportId,
        public string $actorId,
        public string $findingSummary,
        public string $severity,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['criticalFindingId' => $criticalFindingId, 'reportId' => $reportId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RecordCriticalFinding::\${$name} must be a UUID (IC §3).");
            }
        }

        if (trim($findingSummary) === '') {
            throw new InvalidArgumentException('RecordCriticalFinding::$findingSummary must not be blank.');
        }

        if (trim($severity) === '') {
            throw new InvalidArgumentException('RecordCriticalFinding::$severity must not be blank (§46).');
        }
    }

    public function policyAbility(): string
    {
        return 'create';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'radiology.critical_finding_identified',
            actorId: $this->actorId,
            targetType: 'radiology_critical_finding',
            targetId: $this->criticalFindingId,
            metadata: [
                'report_id' => $this->reportId,
                'severity' => strtoupper(trim($this->severity)),
            ],
        );
    }
}
