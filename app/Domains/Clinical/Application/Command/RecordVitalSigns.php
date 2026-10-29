<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\Model\VitalSignsObservation;
use App\Domains\Clinical\Domain\ValueObject\VitalSigns;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Record one complete vitals set against an encounter (12-clinical-domain.md
 * §17–§22; ADR-131, ADR-132, ADR-135, ADR-138).
 *
 * `$vitalSignsObservationId` is client-minted (a UUIDv7) so this `Auditable`
 * command names its own target before the row exists (the `CreateClinicalNote`
 * precedent) and repeat delivery is idempotent. `$actorId` is the authenticated
 * recorder. `tenant_id` is resolved from `TenantContext`; `facility_id` is taken
 * from the ENCOUNTER context by the handler (ADR-135), never from this command.
 *
 * `$measurements` is the validated {@see VitalSigns} value object — at least one
 * of the nine core measurements is non-null, every value is within the ADR-138
 * structural safety bounds, and °C / kg / cm are rounded to one decimal place.
 *
 * `$authoredContext` is `CLINICIAN` (default) or `NURSING` (ADR-138 D8 — the
 * only source metadata C.2 records). `$occurredAt` is the clinical time of the
 * set (ISO-8601, optional — defaults to server `now()`); never in the future.
 * When it is more than {@see BACKDATE_REASON_THRESHOLD_HOURS} hours earlier than
 * now, `$reason` is REQUIRED (ADR-132) — it is written to the AUDIT row only,
 * never to a `clin_` column.
 *
 * Class-level (create) authorization: the bus checks
 * `VitalSignsObservationPolicy::record`. The handler adds the ADR-133
 * per-encounter-facility refinement.
 */
final readonly class RecordVitalSigns implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated documentation must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $vitalSignsObservationId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public VitalSigns $measurements,
        public string $authoredContext = VitalSignsObservation::AUTHORED_CONTEXT_CLINICIAN,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($vitalSignsObservationId)) {
            throw new InvalidArgumentException('RecordVitalSigns::$vitalSignsObservationId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('RecordVitalSigns::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('RecordVitalSigns::$patientId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($encounterId)) {
            throw new InvalidArgumentException('RecordVitalSigns::$encounterId must be a UUID (IC §3).');
        }

        if (! in_array($authoredContext, VitalSignsObservation::AUTHORED_CONTEXTS, true)) {
            throw new InvalidArgumentException('RecordVitalSigns::$authoredContext must be CLINICIAN or NURSING.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('RecordVitalSigns::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('RecordVitalSigns::$occurredAt must not be in the future (12 §96, ADR-132).');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'RecordVitalSigns::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'clinical.vitals.recorded',
            actorId: $this->actorId,
            targetType: 'vital_signs_observation',
            targetId: $this->vitalSignsObservationId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
