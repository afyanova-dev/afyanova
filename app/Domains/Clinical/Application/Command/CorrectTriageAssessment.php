<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\TriageAssessment;
use App\Domains\Clinical\Domain\ValueObject\TriagePriority;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Correct or re-triage a recorded triage assessment by a SUPERSEDING assessment
 * (ADR-145 D-F). A recorded assessment's clinical content
 * (`chief_complaint`, `triage_priority`, `acuity`, `consciousness`,
 * `source_vitals_observation_id`, `occurred_at`, `recorded_at`,
 * `authored_context`) is IMMUTABLE. To correct it — or to re-triage a patient
 * whose condition changed — a NEW `TriageAssessment` is recorded carrying the
 * current values, `supersedes_triage_assessment_id` pointing at the original,
 * and a mandatory `reason` (the rationale — CLINICAL data stored on the new row,
 * never on the audit, never on the event). The original row receives a ONE-TIME
 * `superseded_at` + `superseded_by_triage_assessment_id` stamp; its values are
 * untouched. There is NO in-place edit and NO `UpdateTriageAssessment` /
 * `CancelTriageAssessment` / `VoidTriageAssessment` command.
 *
 * `$newTriageAssessmentId` is client-minted (a UUIDv7) so this `Auditable`
 * command names the created row before it exists. `$supersedesTriageAssessmentId`
 * is the assessment being superseded — a per-subject target the bus loads for
 * the `correct` policy check (which requires `clinical.triage.record` at the
 * assessment's facility — the SAME key as recording; ADR-145 D-G). There is NO
 * separate `clinical.triage.correct` permission.
 *
 * NOT encounter-state-gated: the superseding assessment restates an
 * already-originated triage event on the same attendance, whose patient /
 * encounter / facility context is copied from the original (the ADR-135
 * post-origination principle; the C.2 / C.3 precedent). `$sourceVitalsObservationId`
 * is re-supplied by the caller and revalidated by the handler against the copied
 * encounter.
 *
 * `$occurredAt` (optional) re-states the clinical time; when null it is
 * inherited from the original. Never in the future. There is no separate > 24 h
 * backdate check — the mandatory `$reason` is the justification.
 */
final readonly class CorrectTriageAssessment implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $newTriageAssessmentId,
        public string $supersedesTriageAssessmentId,
        public string $actorId,
        public string $chiefComplaint,
        public string $triagePriority,
        public string $reason,
        public ?string $acuity = null,
        public ?string $consciousness = null,
        public ?string $sourceVitalsObservationId = null,
        public string $authoredContext = TriageAssessment::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
    ) {
        if (! Str::isUuid($newTriageAssessmentId)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$newTriageAssessmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($supersedesTriageAssessmentId)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$supersedesTriageAssessmentId must be a UUID (IC §3).');
        }

        if ($newTriageAssessmentId === $supersedesTriageAssessmentId) {
            throw new InvalidArgumentException('CorrectTriageAssessment: a triage assessment cannot supersede itself.');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$actorId must be a UUID (IC §3).');
        }

        if ($sourceVitalsObservationId !== null && ! Str::isUuid($sourceVitalsObservationId)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$sourceVitalsObservationId must be a UUID or null (IC §3).');
        }

        if (trim($chiefComplaint) === '') {
            throw new InvalidArgumentException('CorrectTriageAssessment::$chiefComplaint must not be blank.');
        }

        if (! TriagePriority::isValid($triagePriority)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$triagePriority must be one of EMERGENCY / URGENT / HIGH / NORMAL / LOW.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('CorrectTriageAssessment::$reason is required.');
        }

        if (! in_array($authoredContext, TriageAssessment::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('CorrectTriageAssessment::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CorrectTriageAssessment::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('CorrectTriageAssessment::$occurredAt must not be in the future (12 §96, ADR-132).');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'correct';
    }

    public function subjectId(): string
    {
        return $this->supersedesTriageAssessmentId; // per-subject — the bus loads the assessment being superseded (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.triage.corrected',
            actorId: $this->actorId,
            targetType: 'triage_assessment',
            targetId: $this->newTriageAssessmentId,
        );
    }
}
