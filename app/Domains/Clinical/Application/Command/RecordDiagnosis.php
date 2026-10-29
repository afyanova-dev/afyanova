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
 * Record one encounter diagnosis (12-clinical-domain.md §23–§27, §73; ADR-131,
 * ADR-132, ADR-135, ADR-139).
 *
 * `$diagnosisId` is client-minted (a UUIDv7) so this `Auditable` command names
 * its own target before the row exists (the `CreateClinicalNote` /
 * `RecordVitalSigns` precedent) and repeat delivery is idempotent. `$actorId`
 * is the authenticated clinician — the diagnosis author (`authored_context` is
 * always `CLINICIAN`, hardcoded, ADR-139 D13). `tenant_id` is resolved from
 * `TenantContext`; `facility_id` is taken from the ENCOUNTER context by the
 * handler (ADR-135), never from this command.
 *
 * `$coding` is the validated {@see DiagnosisCode} value object — `code` optional
 * (open string), `code_system` required iff `code` present (open string), and
 * neither is validated against any terminology (ADR-139 D2). `$description` is
 * REQUIRED non-blank clinical text (D7). `$clinicalStatus` is an open coded
 * string (default `WORKING`), behaviour-neutral (D3).
 *
 * `$occurredAt` is the clinical time the diagnostic conclusion was reached
 * (ISO-8601, optional — defaults to server `now()`); never in the future. When
 * it is more than {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than
 * now, `$reason` is REQUIRED (ADR-132) — it is written to the AUDIT row only,
 * never to a `clin_` column.
 *
 * Class-level (create) authorization: the bus checks `DiagnosisPolicy::record`.
 * The handler adds the ADR-139 per-encounter-facility refinement.
 */
final readonly class RecordDiagnosis implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $diagnosisId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public DiagnosisCode $coding,
        public string $description,
        public string $clinicalStatus = Diagnosis::CLINICAL_STATUS_DEFAULT,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($diagnosisId)) {
            throw new InvalidArgumentException('RecordDiagnosis::$diagnosisId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordDiagnosis::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RecordDiagnosis::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RecordDiagnosis::$encounterId must be a UUID (IC §3).');
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('RecordDiagnosis::$description must not be blank.');
        }

        if (trim($clinicalStatus) === '') {
            throw new InvalidArgumentException('RecordDiagnosis::$clinicalStatus must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordDiagnosis::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordDiagnosis::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordDiagnosis::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'clinical.diagnoses.recorded',
            actorId: $this->actorId,
            targetType: 'diagnosis',
            targetId: $this->diagnosisId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
