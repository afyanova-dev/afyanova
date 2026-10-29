<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Domains\Radiology\Domain\Exception\ReportAmendmentIncomplete;
use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Correct or addend a finalized radiology report ({VERIFIED | RELEASED |
 * AMENDED} → AMENDED) (15-radiology-domain.md §44, §96, §158, §164;
 * ADR-168 D-D). A `CORRECTION` replaces the section text; an `ADDENDUM` appends
 * labelled supplementary text. `$reason` is required, row-only + on the audit
 * row, never in the event (ADR-136). The corrected sections default to the
 * report's current text (a section left `null` clears it).
 *
 * `$amendmentId` is client-minted (UUIDv7) — the append-only row id.
 */
final readonly class AmendRadiologyReport implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $amendmentId,
        public string $reportId,
        public string $actorId,
        public string $amendmentType,
        public string $reason,
        public ?string $findingsText = null,
        public ?string $impressionText = null,
        public ?string $recommendationsText = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['amendmentId' => $amendmentId, 'reportId' => $reportId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AmendRadiologyReport::\${$name} must be a UUID (IC §3).");
            }
        }

        if (! in_array(strtoupper(trim($amendmentType)), [RadiologyReport::AMENDMENT_CORRECTION, RadiologyReport::AMENDMENT_ADDENDUM], true)) {
            throw new InvalidArgumentException('AmendRadiologyReport::$amendmentType must be CORRECTION or ADDENDUM (§44).');
        }

        if (trim($reason) === '') {
            throw ReportAmendmentIncomplete::missingReason();
        }
    }

    public function normalisedType(): string
    {
        return strtoupper(trim($this->amendmentType));
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
            action: 'radiology.report_amended',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
            metadata: ['amendment_type' => $this->normalisedType()],
            reason: trim($this->reason),
        );
    }
}
