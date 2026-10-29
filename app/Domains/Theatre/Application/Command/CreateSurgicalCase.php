<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Originate a surgical case as REQUESTED (17-theatre-domain.md §9; ADR-150 D-C/D-H).
 *
 * `$caseId` is client-minted (UUIDv7) — the `CreateClinicalNote` precedent.
 * `facility_id` is taken from the ENCOUNTER context by the handler (ADR-150
 * D-H), never from this command. `priority` is open-coded (normalized
 * uppercase/trim, not validated against a fixed list — the ward_type
 * convention). `$occurredAt` follows the ADR-132 backdate-reason rule.
 */
final readonly class CreateSurgicalCase implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated origination must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $caseId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $priority = 'ELECTIVE',
        public ?string $plannedProcedure = null,
        public ?string $indication = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('CreateSurgicalCase::$caseId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateSurgicalCase::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('CreateSurgicalCase::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('CreateSurgicalCase::$encounterId must be a UUID (IC §3).');
        }

        if (trim($priority) === '') {
            throw new InvalidArgumentException('CreateSurgicalCase::$priority must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateSurgicalCase::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateSurgicalCase::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateSurgicalCase::$reason is required when $occurredAt is more than %d hours in the past.',
                    self::BACKDATE_REASON_THRESHOLD_HOURS,
                ));
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
            action: 'theatre.case_created',
            actorId: $this->actorId,
            targetType: 'surgical_case',
            targetId: $this->caseId,
            metadata: [
                'patient_id' => $this->patientId,
                'encounter_id' => $this->encounterId,
                'priority' => $this->priority,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
