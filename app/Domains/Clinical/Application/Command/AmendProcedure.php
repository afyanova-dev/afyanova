<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ProcedureAmendmentType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Correct a completed / aborted procedure's record — the privileged
 * post-execution correction (13-clinical-procedures-domain.md §81, §139, Inv
 * 6; ADR-137, ADR-146 D-M). Legal only from `COMPLETED` or `ABORTED`.
 *
 * Applies the caller-supplied new values for the amendable fields (only the
 * ones explicitly passed — `null` means "leave unchanged", so a field cannot
 * be explicitly cleared back to `null` via this command) AND appends one
 * `clin_procedure_amendments` row capturing the correction narrative, the
 * REQUIRED `reason`, and which fields changed. `clin_procedures` is never
 * silently updated outside this controlled, fully-audited path — at least one
 * amendable field must be supplied.
 *
 * IMMUTABLE always (not accepted here): identity, `procedure_number`,
 * `definition_id` / `definition_version`, patient / encounter / facility,
 * `status`, every transition timestamp, requested_by / authorized_by
 * attribution.
 *
 * Per-subject command (`subjectId() = $procedureId`): the bus loads the
 * procedure and calls `ProcedurePolicy::amend` (`clinical.procedures.amend` —
 * the privileged key, at the procedure's own facility) before the handler
 * runs.
 */
final readonly class AmendProcedure implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $procedureId,
        public string $actorId,
        public string $amendmentType,
        public string $body,
        public string $reason,
        public ?string $outcome = null,
        public ?string $outcomeDetail = null,
        public ?string $complications = null,
        public ?string $site = null,
        public ?string $laterality = null,
        public ?string $performedByUserId = null,
        public ?string $statusReason = null,
        public ?string $locationLabel = null,
        public ?string $occurredAt = null,
    ) {
        if (! Str::isUuid($procedureId)) {
            throw new InvalidArgumentException('AmendProcedure::$procedureId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('AmendProcedure::$actorId must be a UUID (IC §3).');
        }

        if (! ProcedureAmendmentType::isValid($amendmentType)) {
            throw new InvalidArgumentException('AmendProcedure::$amendmentType must be a ProcedureAmendmentType value.');
        }

        if (trim($body) === '') {
            throw new InvalidArgumentException('AmendProcedure::$body must not be blank.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('AmendProcedure::$reason is required for an amendment.');
        }

        if ($this->changedFields() === []) {
            throw new InvalidArgumentException('AmendProcedure must change at least one amendable field.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('AmendProcedure::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('AmendProcedure::$occurredAt must not be in the future (12 §96, ADR-132).');
            }
        }
    }

    /**
     * The amendable fields this command actually supplies — `null` on the
     * command means "leave unchanged", so it is excluded here.
     *
     * @return array<string, string>
     */
    public function changedFields(): array
    {
        return array_filter([
            'outcome' => $this->outcome,
            'outcomeDetail' => $this->outcomeDetail,
            'complications' => $this->complications,
            'site' => $this->site,
            'laterality' => $this->laterality,
            'performedByUserId' => $this->performedByUserId,
            'statusReason' => $this->statusReason,
            'locationLabel' => $this->locationLabel,
        ], static fn (?string $value): bool => $value !== null);
    }

    public function policyAbility(): string
    {
        return 'amend';
    }

    public function subjectId(): string
    {
        return $this->procedureId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'procedures.amended',
            actorId: $this->actorId,
            targetType: 'clinical_procedure',
            targetId: $this->procedureId,
        );
    }
}
