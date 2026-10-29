<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain\Model;

use App\Domains\Insurance\Database\Factories\PlanCoverageRuleFactory;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleStatus;
use App\Shared\Domain\AggregateRoot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One effective-dated coverage rule for a (plan, service_category) scope
 * (21-insurance-and-payer-domain.md §31–§45, §69; ADR-200 D-A).
 *
 * The `bill_tariffs` (`Tariff`) shape: effective-dated, terms IMMUTABLE once
 * written, closed exactly once — {@see self::supersede()} (a later
 * `SetPlanCoverageRule`) or {@see self::end()} (`EndPlanCoverageRule`) — never
 * re-opened (§162 / §225 / §331). No events (configuration).
 *
 * @domain      Insurance
 *
 * @authority   authoritative
 *
 * @tenant      required
 *
 * @facility    not-applicable
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $plan_id
 * @property string $service_category
 * @property string $coverage_status
 * @property int|null $coverage_percent
 * @property string|null $co_pay_amount
 * @property string $co_pay_currency
 * @property string|null $fixed_benefit_amount
 * @property bool $authorization_required
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property string $status
 * @property string|null $created_by_user_id
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $recorded_at
 * @property int $version
 */
final class PlanCoverageRule extends AggregateRoot
{
    /** @use HasFactory<PlanCoverageRuleFactory> */
    use HasFactory;

    public const STATUS_CURRENT = CoverageRuleStatus::CURRENT;

    public const STATUS_SUPERSEDED = CoverageRuleStatus::SUPERSEDED;

    public const STATUS_ENDED = CoverageRuleStatus::ENDED;

    protected $table = 'ins_plan_coverage_rules';

    /** @var list<string> */
    protected $fillable = [
        'id', 'tenant_id',
        'plan_id', 'service_category',
        'coverage_status', 'coverage_percent',
        'co_pay_amount', 'co_pay_currency', 'fixed_benefit_amount',
        'authorization_required',
        'effective_from', 'effective_to', 'status',
        'created_by_user_id',
        'occurred_at', 'recorded_at',
        'version',
    ];

    protected static function newFactory(): PlanCoverageRuleFactory
    {
        return PlanCoverageRuleFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'coverage_percent' => 'integer',
            'co_pay_amount' => 'decimal:4',
            'fixed_benefit_amount' => 'decimal:4',
            'authorization_required' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'occurred_at' => 'immutable_datetime',
            'recorded_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /**
     * @param  numeric-string|null  $coPayAmount  already `Money::of()`-normalised
     * @param  numeric-string|null  $fixedBenefitAmount  already `Money::of()`-normalised
     */
    public static function open(
        string $id,
        string $tenantId,
        string $planId,
        string $serviceCategory,
        string $coverageStatus,
        ?int $coveragePercent,
        ?string $coPayAmount,
        string $coPayCurrency,
        ?string $fixedBenefitAmount,
        bool $authorizationRequired,
        CarbonImmutable $effectiveFrom,
        string $createdByUserId,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): self {
        return new self([
            'id' => $id,
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'service_category' => $serviceCategory,
            'coverage_status' => $coverageStatus,
            'coverage_percent' => $coveragePercent,
            'co_pay_amount' => $coPayAmount,
            'co_pay_currency' => $coPayCurrency,
            'fixed_benefit_amount' => $fixedBenefitAmount,
            'authorization_required' => $authorizationRequired,
            'effective_from' => $effectiveFrom,
            'effective_to' => null,
            'status' => self::STATUS_CURRENT,
            'created_by_user_id' => $createdByUserId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
        ]);
    }

    /** CURRENT → SUPERSEDED — a later `SetPlanCoverageRule` takes over from `$at` (exclusive). */
    public function supersede(CarbonImmutable $at): void
    {
        $this->effective_to = $at;
        $this->status = self::STATUS_SUPERSEDED;
    }

    /** CURRENT → ENDED — `EndPlanCoverageRule`; the scope has no rule from `$at` (exclusive). */
    public function end(CarbonImmutable $at): void
    {
        $this->effective_to = $at;
        $this->status = self::STATUS_ENDED;
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_CURRENT;
    }
}
