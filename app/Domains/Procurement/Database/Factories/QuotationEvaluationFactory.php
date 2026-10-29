<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\QuotationEvaluation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuotationEvaluation>
 */
class QuotationEvaluationFactory extends Factory
{
    protected $model = QuotationEvaluation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'rfq_id' => (string) Str::uuid7(),
            'quotation_id' => (string) Str::uuid7(),
            'evaluator_user_id' => (string) Str::uuid7(),
            'commercial_score' => 80,
            'technical_score' => 70,
            'quality_score' => 75,
            'delivery_score' => 65,
            'overall_score' => null,
            'comments' => null,
            'status' => QuotationEvaluation::STATUS_DRAFT,
            'submitted_at' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => CarbonImmutable::now(),
            'recorded_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function submitted(): static
    {
        return $this->state([
            'status' => QuotationEvaluation::STATUS_SUBMITTED,
            'overall_score' => 74,
            'submitted_at' => CarbonImmutable::now(),
        ]);
    }
}
