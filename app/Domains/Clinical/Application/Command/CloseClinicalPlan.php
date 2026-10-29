<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Command;

use App\Domains\Clinical\Domain\ValueObject\ClinicalPlanStatus;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Close a ClinicalPlan (COMPLETED or DISCONTINUED) (ADR-143 D-E / D-S).
 */
final readonly class CloseClinicalPlan implements Auditable, AuthorizesAgainst
{
    public function __construct(
        public string $clinicalPlanId,
        public string $actorId,
        public string $outcome,
        public ?string $reason = null,
        public ?string $occurredAt = null,
        public ?string $idempotencyKey = null,
    ) {
        if (! Str::isUuid($clinicalPlanId)) {
            throw new InvalidArgumentException('CloseClinicalPlan::$clinicalPlanId must be a UUID (IC §3).');
        }

        if (! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('CloseClinicalPlan::$actorId must be a UUID (IC §3).');
        }

        if (! in_array($outcome, [ClinicalPlanStatus::COMPLETED, ClinicalPlanStatus::DISCONTINUED], true)) {
            throw new InvalidArgumentException(sprintf(
                'CloseClinicalPlan::$outcome must be COMPLETED or DISCONTINUED, got [%s].',
                $outcome,
            ));
        }

        if ($outcome === ClinicalPlanStatus::DISCONTINUED && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException(
                'CloseClinicalPlan::$reason is required when outcome is DISCONTINUED.'
            );
        }

        if ($outcome === ClinicalPlanStatus::COMPLETED && $reason !== null && trim($reason) !== '') {
            throw new InvalidArgumentException(
                'CloseClinicalPlan::$reason is forbidden when outcome is COMPLETED.'
            );
        }

        if ($occurredAt !== null) {
            try {
                $occurred = CarbonImmutable::parse($occurredAt);
            } catch (Throwable) {
                throw new InvalidArgumentException('CloseClinicalPlan::$occurredAt must be an ISO-8601 datetime or null.');
            }

            if ($occurred->greaterThan(CarbonImmutable::now())) {
                throw new InvalidArgumentException('CloseClinicalPlan::$occurredAt must not be in the future.');
            }
        }
    }

    public function policyAbility(): string
    {
        return 'close';
    }

    public function subjectId(): string
    {
        return $this->clinicalPlanId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'clinical.plans.closed',
            actorId: $this->actorId,
            targetType: 'clinical_plan',
            targetId: $this->clinicalPlanId,
            reason: null,
        );
    }
}
