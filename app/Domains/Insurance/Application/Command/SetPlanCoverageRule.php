<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Command;

use App\Domains\Insurance\Domain\Exception\InvalidCoverageRule;
use App\Domains\Insurance\Domain\Money;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleOutcome;
use App\Domains\Insurance\Domain\ValueObject\CoveredServiceCategory;
use App\Shared\Audit\Auditable;
use App\Shared\Audit\AuditEntry;
use App\Shared\Bus\AuthorizesAgainst;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Set a new effective-dated coverage rule for a (plan, service_category)
 * (21-insurance-and-payer-domain.md §31–§45, §69; ADR-200 D-B). Class-level
 * `manage` (`insurance.coverage.manage`).
 *
 * The `SetTariff` pattern: the handler closes the prior open row (exclusive
 * `effective_to`) and rejects an `effectiveFrom` not strictly after it. Rule
 * terms are immutable once written — a change is a new command / new row.
 */
final readonly class SetPlanCoverageRule implements Auditable, AuthorizesAgainst
{
    public string $serviceCategory;

    public string $coverageStatus;

    public ?int $coveragePercent;

    /** @var numeric-string|null */
    public ?string $coPayAmount;

    /** @var numeric-string|null */
    public ?string $fixedBenefitAmount;

    public bool $authorizationRequired;

    public CarbonImmutable $effectiveFrom;

    public function __construct(
        public string $ruleId,
        public string $actorId,
        public string $planId,
        string $serviceCategory,
        string $coverageStatus,
        string $effectiveFrom,
        ?int $coveragePercent = null,
        ?string $coPayAmount = null,
        ?string $fixedBenefitAmount = null,
        ?bool $authorizationRequired = null,
        public ?string $idempotencyKey = null,
    ) {
        foreach (['ruleId' => $ruleId, 'actorId' => $actorId, 'planId' => $planId] as $field => $value) {
            if (! Str::isUuid($value)) {
                throw new InvalidArgumentException("SetPlanCoverageRule::\${$field} must be a UUID (IC §3).");
            }
        }

        $this->serviceCategory = CoveredServiceCategory::assert($serviceCategory);
        $this->coverageStatus = CoverageRuleOutcome::assert($coverageStatus);

        try {
            $this->effectiveFrom = CarbonImmutable::parse($effectiveFrom)->startOfDay();
        } catch (\Throwable) {
            throw new InvalidArgumentException('SetPlanCoverageRule::$effectiveFrom must be a valid date.');
        }

        if ($coveragePercent !== null && ($coveragePercent < 0 || $coveragePercent > 100)) {
            throw InvalidCoverageRule::percentOutOfRange($coveragePercent);
        }

        $coPay = $coPayAmount !== null ? Money::of($coPayAmount) : null;
        $fixed = $fixedBenefitAmount !== null ? Money::of($fixedBenefitAmount) : null;

        if ($this->coverageStatus === CoverageRuleOutcome::NOT_COVERED
            && ($coveragePercent !== null || $coPay !== null || $fixed !== null)) {
            throw InvalidCoverageRule::notCoveredWithTerms();
        }

        $this->coveragePercent = $coveragePercent;
        $this->coPayAmount = $coPay;
        $this->fixedBenefitAmount = $fixed;
        $this->authorizationRequired = $authorizationRequired
            ?? ($this->coverageStatus === CoverageRuleOutcome::REQUIRES_AUTHORIZATION);
    }

    public function policyAbility(): string
    {
        return 'manage';
    }

    public function subjectId(): ?string
    {
        return null;
    }

    public function auditEntry(): AuditEntry
    {
        return new AuditEntry(
            action: 'insurance.plan_coverage_rule_set',
            actorId: $this->actorId,
            targetType: 'plan_coverage_rule',
            targetId: $this->ruleId,
            metadata: [
                'plan_id' => $this->planId,
                'service_category' => $this->serviceCategory,
                'coverage_status' => $this->coverageStatus,
            ],
        );
    }
}
