<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\Diagnosis;
use App\Domains\Clinical\Domain\ValueObject\DiagnosisCode;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Correct a recorded diagnosis by a SUPERSEDING diagnosis (12-clinical-domain.md
 * §94; ADR-139 D4).
 *
 * A recorded diagnosis's clinical content (`code`, `code_system`,
 * `description`, `clinical_status`, `occurred_at`, `recorded_at`,
 * `authored_context`) is IMMUTABLE. To correct it a NEW `Diagnosis` is recorded
 * carrying the corrected values, `supersedes_diagnosis_id` pointing at the
 * original, and a mandatory `reason` (the correction rationale — CLINICAL data
 * stored on the new row, never on the audit, never on the event). The original
 * row receives a ONE-TIME `superseded_at` + `superseded_by_diagnosis_id` stamp;
 * its values are untouched. There is NO in-place edit and NO `UpdateDiagnosis` /
 * `ResolveDiagnosis` / `CancelDiagnosis` / `VoidDiagnosis` command in C.3.
 *
 * `$newDiagnosisId` is client-minted (a UUIDv7) so this `Auditable` command
 * names the created row before it exists. `$supersedesDiagnosisId` is the
 * diagnosis being corrected — a per-subject target the bus loads for the
 * `correct` policy check (which requires `clinical.diagnoses.record` at the
 * diagnosis's facility — the SAME key as recording; ADR-139 D9). There is NO
 * separate `clinical.diagnoses.correct` permission.
 *
 * NOT encounter-state-gated and makes NO cross-domain call: the correction
 * restates an already-originated conclusion, whose patient / encounter /
 * facility context is copied from the original (the ADR-135 post-origination
 * principle; the C.2 precedent).
 *
 * `$occurredAt` (optional) re-states the clinical time; when null it is
 * inherited from the original. Never in the future. There is no separate > 24 h
 * backdate check — the mandatory `$reason` is the justification.
 */
final readonly class CorrectDiagnosis implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newDiagnosisId,
        public string $supersedesDiagnosisId,
        public string $actorId,
        public DiagnosisCode $coding,
        public string $description,
        public string $reason,
        public string $clinicalStatus = Diagnosis::CLINICAL_STATUS_DEFAULT,
        public ?string $occurredAt = null,
    ) {
        if (! Str::isUuid($newDiagnosisId)) {
            throw new InvalidArgumentException('CorrectDiagnosis::$newDiagnosisId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($supersedesDiagnosisId)) {
            throw new InvalidArgumentException('CorrectDiagnosis::$supersedesDiagnosisId must be a UUID (IC §3).');
        }

        if ($newDiagnosisId === $supersedesDiagnosisId) {
            throw new InvalidArgumentException('CorrectDiagnosis: a diagnosis cannot supersede itself.');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CorrectDiagnosis::$actorId must be a UUID (IC §3).');
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('CorrectDiagnosis::$description must not be blank.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CorrectDiagnosis::$reason is required.');
        }

        if (trim($clinicalStatus) === '') {
            throw new InvalidArgumentException('CorrectDiagnosis::$clinicalStatus must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CorrectDiagnosis::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('CorrectDiagnosis::$occurredAt must not be in the future (12 §96, ADR-132).');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'correct';
    }

    public function subjectId(): string
    {
        return $this->supersedesDiagnosisId; // per-subject — the bus loads the diagnosis being corrected (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.diagnoses.corrected',
            actorId: $this->actorId,
            targetType: 'diagnosis',
            targetId: $this->newDiagnosisId,
        );
    }
}
