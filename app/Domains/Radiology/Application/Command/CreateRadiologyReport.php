<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Create the radiology report for a completed imaging study
 * (15-radiology-domain.md §40–§41, §165; ADR-167 D-D). Creates it at DRAFT.
 *
 * `$reportId` is client-minted (UUIDv7). `order_id` / `patient_id` /
 * `encounter_id` / `accession_number` / `modality_code` / `service_code` are
 * snapshotted from the study by the handler. The body sections are free text,
 * ROW-ONLY (ADR-136) and all optional at create — `findings_text` +
 * `impression_text` become required at VERIFY.
 */
final readonly class CreateRadiologyReport implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $reportId,
        public string $studyId,
        public string $actorId,
        public ?string $clinicalIndicationText = null,
        public ?string $techniqueText = null,
        public ?string $findingsText = null,
        public ?string $impressionText = null,
        public ?string $recommendationsText = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['reportId' => $reportId, 'studyId' => $studyId, 'actorId' => $actorId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateRadiologyReport::\${$name} must be a UUID (IC §3).");
            }
        }

        foreach ([
            'clinicalIndicationText' => $clinicalIndicationText,
            'techniqueText' => $techniqueText,
            'findingsText' => $findingsText,
            'impressionText' => $impressionText,
            'recommendationsText' => $recommendationsText,
        ] as $name => $value) {
            if ($value !== null && trim($value) === '') {
                throw new InvalidArgumentException("CreateRadiologyReport::\${$name} must be a non-blank string or null.");
            }
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
            action: 'radiology.report_created',
            actorId: $this->actorId,
            targetType: 'radiology_report',
            targetId: $this->reportId,
            metadata: ['study_id' => $this->studyId],
        );
    }
}
