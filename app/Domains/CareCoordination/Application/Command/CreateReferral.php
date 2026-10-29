<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Command;

use App\Domains\CareCoordination\Domain\Exception\ConsentRequiredForReferral;
use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Originate a referral as DRAFT (26-care-coordination-domain.md §6, §9;
 * ADR-151 D-D/D-G). `facility_id` is taken from the caller's tenant/facility
 * context via `TenantContext` (source_facility_id — ADR-132 convention),
 * `patient_id` NOT NULL regardless of `sourceEncounterId` (ADR-151 D-D's own
 * gap fix). `targetType` is validated INTERNAL|EXTERNAL (one of the few
 * closed-set open-coded fields, alongside `direction`); EXTERNAL requires a
 * non-null `consentBasisRef` (Invariant 7.10).
 */
final readonly class CreateReferral implements Auditable, AuthorizesAgainst
{
    /** ADR-132 — beyond this, backdated origination must carry a `reason`. */
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $referralId,
        public string $actorId,
        public string $patientId,
        public string $targetType,
        public string $targetRef,
        public ?string $sourceEncounterId = null,
        public string $direction = Referral::DIRECTION_OUTBOUND,
        public bool $impliesHandover = false,
        public ?string $specialtyRequested = null,
        public string $priority = 'ROUTINE',
        public ?string $clinicalReasonRef = null,
        public ?string $consentBasisRef = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($referralId)) {
            throw new InvalidArgumentException('CreateReferral::$referralId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CreateReferral::$actorId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('CreateReferral::$patientId must be a UUID (IC §3).');
        }

        if ($sourceEncounterId !== null && ! Str::isUuid($sourceEncounterId)) {
            throw new InvalidArgumentException('CreateReferral::$sourceEncounterId must be a UUID or null (IC §3).');
        }

        if (! in_array($direction, [Referral::DIRECTION_OUTBOUND, Referral::DIRECTION_INBOUND], true)) {
            throw new InvalidArgumentException('CreateReferral::$direction must be OUTBOUND or INBOUND.');
        }

        if (! in_array($targetType, [Referral::TARGET_TYPE_INTERNAL, Referral::TARGET_TYPE_EXTERNAL], true)) {
            throw new InvalidArgumentException('CreateReferral::$targetType must be INTERNAL or EXTERNAL.');
        }

        if (trim($targetRef) === '') {
            throw new InvalidArgumentException('CreateReferral::$targetRef must not be blank.');
        }

        if (trim($priority) === '') {
            throw new InvalidArgumentException('CreateReferral::$priority must not be blank.');
        }

        if ($targetType === Referral::TARGET_TYPE_EXTERNAL && ($consentBasisRef === null || trim($consentBasisRef) === '')) {
            throw ConsentRequiredForReferral::forExternalTarget();
        }

        if ($consentBasisRef !== null && ! Str::isUuid($consentBasisRef)) {
            throw new InvalidArgumentException('CreateReferral::$consentBasisRef must be a UUID or null (IC §3).');
        }

        if ($clinicalReasonRef !== null && ! Str::isUuid($clinicalReasonRef)) {
            throw new InvalidArgumentException('CreateReferral::$clinicalReasonRef must be a UUID or null (IC §3).');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateReferral::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateReferral::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateReferral::$reason is required when $occurredAt is more than %d hours in the past.',
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
            action: 'care_coordination.referral_created',
            actorId: $this->actorId,
            targetType: 'referral',
            targetId: $this->referralId,
            metadata: [
                'patient_id' => $this->patientId,
                'target_type' => $this->targetType,
                'priority' => $this->priority,
            ],
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
