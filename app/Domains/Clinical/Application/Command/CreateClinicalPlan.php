<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Create a new ClinicalPlan for a patient (ADR-143 D-C / D-D / D-S).
 */
final readonly class CreateClinicalPlan implements Auditable, AuthorizesAgainst
{
    public const BACKDATE_REASON_THRESHOLD_HOURS = 24;

    public function __construct(
        public string $clinicalPlanId,
        public string $actorId,
        public string $patientId,
        public string $encounterId,
        public string $planType,
        public string $clinicalIntent,
        public ?string $goals = null,
        public ?string $plannedActions = null,
        public ?string $instructions = null,
        public ?string $followUp = null,
        public ?string $indicationDiagnosisId = null,
        public ?string $indicationProblemId = null,
        public ?string $occurredAt = null,
        public ?string $reason = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['clinicalPlanId' => $clinicalPlanId, 'actorId' => $actorId, 'patientId' => $patientId, 'encounterId' => $encounterId] as $name => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("CreateClinicalPlan::\${$name} must be a UUID (IC §3).");
            }
        }

        if ($indicationDiagnosisId !== null && ! Str::isUuid($indicationDiagnosisId)) {
            throw new InvalidArgumentException('CreateClinicalPlan::$indicationDiagnosisId must be a UUID or null (IC §3).');
        }

        if ($indicationProblemId !== null && ! Str::isUuid($indicationProblemId)) {
            throw new InvalidArgumentException('CreateClinicalPlan::$indicationProblemId must be a UUID or null (IC §3).');
        }

        ClinicalPlanType::assert($planType);

        if (trim($clinicalIntent) === '') {
            throw new InvalidArgumentException('CreateClinicalPlan::$clinicalIntent must not be blank.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CreateClinicalPlan::$occurredAt must be an ISO-8601 datetime or null.');
            }

            $now = CarbonImmutable::now();

            if ($occurred->greaterThan($now)) {
                throw new InvalidArgumentException('CreateClinicalPlan::$occurredAt must not be in the future.');
            }

            if ($occurred->lessThan($now->subHours(self::BACKDATE_REASON_THRESHOLD_HOURS))
                && ($reason === null || trim($reason) === '')) {
                throw new InvalidArgumentException(sprintf(
                    'CreateClinicalPlan::$reason is required when $occurredAt is more than %d hours in the past.',
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
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.plans.created',
            actorId: $this->actorId,
            targetType: 'clinical_plan',
            targetId: $this->clinicalPlanId,
            reason: ($this->reason !== null && trim($this->reason) !== '') ? trim($this->reason) : null,
        );
    }
}
