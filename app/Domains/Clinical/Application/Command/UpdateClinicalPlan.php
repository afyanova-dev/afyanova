<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanChangeType;
use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanType;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Update the clinical content of a ClinicalPlan (ADR-143 D-E / D-S).
 */
final readonly class UpdateClinicalPlan implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $clinicalPlanId,
        public string $actorId,
        public string $changeType,
        public string $planType,
        public string $clinicalIntent,
        public ?string $goals = null,
        public ?string $plannedActions = null,
        public ?string $instructions = null,
        public ?string $followUp = null,
        public ?string $indicationDiagnosisId = null,
        public ?string $indicationProblemId = null,
        public ?string $reason = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($clinicalPlanId)) {
            throw new InvalidArgumentException('UpdateClinicalPlan::$clinicalPlanId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('UpdateClinicalPlan::$actorId must be a UUID (IC §3).');
        }

        if ($indicationDiagnosisId !== null && ! Str::isUuid($indicationDiagnosisId)) {
            throw new InvalidArgumentException('UpdateClinicalPlan::$indicationDiagnosisId must be a UUID or null (IC §3).');
        }

        if ($indicationProblemId !== null && ! Str::isUuid($indicationProblemId)) {
            throw new InvalidArgumentException('UpdateClinicalPlan::$indicationProblemId must be a UUID or null (IC §3).');
        }

        ClinicalPlanChangeType::assert($changeType);
        ClinicalPlanType::assert($planType);

        if (trim($clinicalIntent) === '') {
            throw new InvalidArgumentException('UpdateClinicalPlan::$clinicalIntent must not be blank.');
        }

        if ($changeType === ClinicalPlanChangeType::CORRECTION && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('UpdateClinicalPlan::$reason is required when changeType is CORRECTION.');
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('UpdateClinicalPlan::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('UpdateClinicalPlan::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'update';
    }

    public function subjectId(): string
    {
        return $this->clinicalPlanId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.plans.updated',
            actorId: $this->actorId,
            targetType: 'clinical_plan',
            targetId: $this->clinicalPlanId,
            reason: null,
        );
    }
}
