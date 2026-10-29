<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Request one clinical procedure against an encounter — the CP.1 origination
 * point (13-clinical-procedures-domain.md §9, §21, §120; ADR-137, ADR-146 D-G).
 *
 * `$procedureId` is client-minted (a UUIDv7) so this `Auditable` command names
 * its own target before the row exists (the `RequestTriageAssessment` /
 * `RecordDiagnosis` precedent) and repeat delivery is idempotent. `$actorId` is
 * the authenticated principal — the requester. `tenant_id` is resolved from
 * `TenantContext`; `facility_id` is taken from the ENCOUNTER context by the
 * handler (ADR-135), never from this command (Invariant 12).
 *
 * `$definitionId` names the `ProcedureDefinition` catalog entry; the handler
 * validates the facility's capability (ADR-120) and snapshots
 * `definition_version` + `procedure_category`. `$indication` is free text
 * (13 §49). `$sourceDiagnosisId` / `$sourceClinicalPlanId` are OPTIONAL
 * read-only provenance pointers validated by the handler (the ADR-140 D-I
 * precedent) — NO Diagnosis / Plan mutation, NO back-reference.
 * `$financialClearanceRef` is a pass-through (13 §57; not gated in PHASE 6).
 *
 * `$occurredAt` is the clinical time care began (ISO-8601, optional — defaults
 * to server `now()`); never in the future. When it is more than
 * {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than now, `$reason` is
 * REQUIRED (ADR-132) — written to the AUDIT row only, never to a `clin_`
 * column.
 *
 * `$authoredContext` is `CLINICIAN` (default) or `NURSING` (ADR-146 D-E — the
 * ADR-144 mechanism; 13 §18, a procedure is frequently nurse-performed).
 *
 * Class-level (create) authorization: the bus checks
 * `ProcedurePolicy::request`. The handler adds the ADR-133 per-encounter-
 * facility refinement.
 */
final readonly class RequestProcedure implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $procedureId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $definitionId,
        public ?string $indication = null,
        public ?string $sourceDiagnosisId = null,
        public ?string $sourceClinicalPlanId = null,
        public ?string $financialClearanceRef = null,
        public string $authoredContext = ClinicalProcedure::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
        public ?string $protocolReference = null,
    ) {
        foreach (['procedureId' => $procedureId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId, 'definitionId' => $definitionId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("RequestProcedure::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($sourceDiagnosisId !== null && ! Str::isUuid($sourceDiagnosisId)) {
            throw new InvalidArgumentException('RequestProcedure::$sourceDiagnosisId must be a UUID or null (IC §3).');
        }

        if ($sourceClinicalPlanId !== null && ! Str::isUuid($sourceClinicalPlanId)) {
            throw new InvalidArgumentException('RequestProcedure::$sourceClinicalPlanId must be a UUID or null (IC §3).');
        }

        if (! in_array($authoredContext, ClinicalProcedure::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('RequestProcedure::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($protocolReference !== null && trim($protocolReference) === '') {
            throw new InvalidArgumentException('RequestProcedure::$protocolReference must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RequestProcedure::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RequestProcedure::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RequestProcedure::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
            }
        }
    }

    public function policyAbility(): string
    {
        return 'request';
    }

    public function subjectId(): ?string
    {
        return null; // create — class-level check (IC §5.1)
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: $this->protocolReference === null ? 'procedures.requested' : 'procedures.protocol_authorized',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
            metadata: $this->protocolReference === null ? [] : [
                'authorization_basis' => 'APPROVED_PROTOCOL',
                'protocol_reference' => trim($this->protocolReference),
            ],
        );
    }
}
