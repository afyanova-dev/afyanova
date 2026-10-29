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
 * Record one triage assessment (ADR-105, ADR-131, ADR-132, ADR-135, ADR-145).
 *
 * `$triageAssessmentId` is client-minted (a UUIDv7) so this `Auditable` command
 * names its own target before the row exists (the `RecordDiagnosis` /
 * `RecordVitalSigns` precedent) and repeat delivery is idempotent. `$actorId`
 * is the authenticated principal — the triage clinician or nurse. `tenant_id`
 * is resolved from `TenantContext`; `facility_id` is taken from the ENCOUNTER
 * context by the handler (ADR-135), never from this command (Invariant 12).
 *
 * `$chiefComplaint` is REQUIRED non-blank free text (the patient's stated reason
 * for the visit — 12 §28 / §49); it is NEVER written to an event payload or an
 * audit row (ADR-136 deny-list; ADR-145 D-E). `$triagePriority` is a CLOSED
 * coded string `{EMERGENCY | URGENT | HIGH | NORMAL | LOW}` (23 §21) — the one
 * behaviour-carrying field, which Queueing & Patient Flow will consume in
 * PHASE 12 (ADR-105; Clinical determines it, Queueing never recomputes it).
 * `$acuity` and `$consciousness` are OPTIONAL open coded strings, documentation
 * only, shape-validated only — no scale enum, no scoring engine (ADR-145 O-1).
 * `$sourceVitalsObservationId` is an OPTIONAL read-only provenance pointer to a
 * C.2 `VitalSignsObservation` on the same encounter (validated by the handler;
 * the ADR-140 D-I precedent) — NO vitals values are carried here.
 *
 * `$occurredAt` is the clinical time the triage was performed (ISO-8601,
 * optional — defaults to server `now()`); never in the future. When it is more
 * than {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than now, `$reason`
 * is REQUIRED (ADR-132) — written to the AUDIT row only, never to a `clin_`
 * column.
 *
 * `$authoredContext` is `CLINICIAN` (default) or `NURSING` (ADR-145 D-M — the
 * ADR-144 mechanism; triage is frequently nurse-performed). Set-membership-
 * validated; NOT an authorization input.
 *
 * Class-level (create) authorization: the bus checks
 * `TriageAssessmentPolicy::record`. The handler adds the ADR-145
 * per-encounter-facility refinement.
 */
final readonly class RecordTriageAssessment implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $triageAssessmentId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $chiefComplaint,
        public string $triagePriority,
        public ?string $acuity = null,
        public ?string $consciousness = null,
        public ?string $sourceVitalsObservationId = null,
        public string $authoredContext = TriageAssessment::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($triageAssessmentId)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$triageAssessmentId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$encounterId must be a UUID (IC §3).');
        }

        if ($sourceVitalsObservationId !== null && ! Str::isUuid($sourceVitalsObservationId)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$sourceVitalsObservationId must be a UUID or null (IC §3).');
        }

        if (trim($chiefComplaint) === '') {
            throw new InvalidArgumentException('RecordTriageAssessment::$chiefComplaint must not be blank.');
        }

        if (! TriagePriority::isValid($triagePriority)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$triagePriority must be one of EMERGENCY / URGENT / HIGH / NORMAL / LOW.');
        }

        if (! in_array($authoredContext, TriageAssessment::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('RecordTriageAssessment::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordTriageAssessment::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordTriageAssessment::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordTriageAssessment::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'record';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.triage.recorded',
            actorId: $this->actorId,
            targetType: 'triage_assessment',
            targetId: $this->triageAssessmentId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
