<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ProblemCode;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Add one longitudinal problem to a patient's problem list
 * (12-clinical-domain.md §25–§27, §74; ADR-131, ADR-132, ADR-135, ADR-140
 * D-A … D-R).
 *
 * `$problemId` is client-minted (a UUIDv7) so this `Auditable` command names
 * its own target before the row exists (the `RecordDiagnosis` precedent) and
 * repeat delivery is idempotent. `$actorId` is the authenticated clinician —
 * the problem author (`authored_context` is always `CLINICIAN`, hardcoded,
 * ADR-140 D-Q). `tenant_id` is resolved from `TenantContext`; `facility_id` is
 * taken from the ENCOUNTER context by the handler (ADR-140 D-B), never from
 * this command.
 *
 * `$coding` is the validated {@see ProblemCode} value object — `code` optional
 * (open string), `code_system` required iff `code` present (open string), and
 * neither is validated against any terminology (ADR-140 D-G). `$description` is
 * REQUIRED non-blank clinical text (D-H). `$onsetDate` (optional, `Y-m-d`) is
 * when the condition began — nullable and, later, mutable via `UpdateProblem`;
 * it may be in the past without a `reason` (the ADR-132 > 24 h backdate rule
 * applies to `occurred_at` / `resolved_at`, not `onset_date` — D-F) but never
 * in the future. `$sourceDiagnosisId` (optional) is PROVENANCE ONLY — a
 * one-way UUID reference the handler validates read-only via the C.3
 * `DiagnosisReader` (same tenant + same patient); it is never a back-reference
 * and never mutates Diagnosis (D-I).
 *
 * `$occurredAt` is the clinical time the problem was added to the list
 * (ISO-8601, optional — defaults to server `now()`); never in the future. When
 * it is more than {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than
 * now, `$reason` is REQUIRED (ADR-132) — it is written to the AUDIT row only,
 * never to a `clin_` column.
 *
 * Class-level (create) authorization: the bus checks `ProblemPolicy::record`.
 * The handler adds the ADR-140 per-encounter-facility refinement.
 */
final readonly class AddProblem implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $problemId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public ProblemCode $coding,
        public string $description,
        public ?string $onsetDate = null,
        public ?string $sourceDiagnosisId = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['problemId' => $problemId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("AddProblem::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($sourceDiagnosisId !== null && ! Str::isUuid($sourceDiagnosisId)) {
            throw new InvalidArgumentException('AddProblem::$sourceDiagnosisId must be a UUID or null (IC §3).');
        }

        if (trim($description) === '') {
            throw new InvalidArgumentException('AddProblem::$description must not be blank.');
        }

        if ($onsetDate !== null) {
            try {
                $onset = CarbonImmutable::parse($onsetDate);
            } catch (Throwable) {
                throw new InvalidArgumentException('AddProblem::$onsetDate must be an ISO-8601 date or null.');
            }

            if ($onset->startOfDay()->greaterThan(CarbonImmutable::now()->startOfDay())) {
                throw new InvalidArgumentException('AddProblem::$onsetDate must not be in the future.');
            }
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('AddProblem::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('AddProblem::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'AddProblem::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'clinical.problems.added',
            actorId: $this->actorId,
            targetType: 'problem',
            targetId: $this->problemId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
