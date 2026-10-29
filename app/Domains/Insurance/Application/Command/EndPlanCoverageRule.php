<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * End the open coverage rule for a (plan, service_category) with no replacement
 * (ADR-200 D-B). Per-subject `manage`. The scope has no rule from `effectiveTo`
 * (exclusive) — `EvaluateCoverage` then returns UNKNOWN for later dates.
 */
final readonly class EndPlanCoverageRule implements Auditable, AuthorizesAgainst
{
    public CarbonImmutable $effectiveTo;

    public function __construct(
        public string $ruleId,
        public string $actorId,
        string $effectiveTo,
    ) {
        if (! Str::isUuid($ruleId) || ! Str::isUuid($actorId)) {
            throw new InvalidArgumentException('EndPlanCoverageRule ids must be UUIDs (IC §3).');
        }

        try {
            $this->effectiveTo = CarbonImmutable::parse($effectiveTo)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('EndPlanCoverageRule::$effectiveTo must be a valid date.');
        }
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): string
    {
        return $this->ruleId;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.plan_coverage_rule_ended',
            actorId: $this->actorId,
            targetType: 'plan_coverage_rule',
            targetId: $this->ruleId,
            reason: $this->effectiveTo->toDateString(),
        );
    }
}
