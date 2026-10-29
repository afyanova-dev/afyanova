<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\PlanCoverageRule;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleOutcome;
use App\Domains\Insurance\Domain\ValueObject\CoverageRuleStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlanCoverageRule>
 */
class PlanCoverageRuleFactory extends Factory
{
    protected $model = PlanCoverageRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'plan_id' => (string) Str::uuid7(),
            'service_category' => 'LABORATORY',
            'coverage_status' => CoverageRuleOutcome::COVERED,
            'coverage_percent' => 80,
            'co_pay_amount' => null,
            'co_pay_currency' => 'TZS',
            'fixed_benefit_amount' => null,
            'authorization_required' => false,
            'effective_from' => $now->subYear()->toDateString(),
            'effective_to' => null,
            'status' => CoverageRuleStatus::CURRENT,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPlan(string $planId): static
    {
        return $this->state(['plan_id' => $planId]);
    }

    public function category(string $category): static
    {
        return $this->state(['service_category' => $category]);
    }
}
