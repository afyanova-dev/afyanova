<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\PurchaseRequisition;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseRequisition>
 */
class PurchaseRequisitionFactory extends Factory
{
    protected $model = PurchaseRequisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'requisition_number' => 'PR-'.fake()->unique()->numerify('########'),
            'requesting_department_ref' => null,
            'priority' => 'ROUTINE',
            'reason' => null,
            'estimated_value' => null,
            'emergency' => false,
            'emergency_reason' => null,
            'status' => PurchaseRequisition::STATUS_DRAFT,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'rejection_reason' => null,
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
        return $this->state(['status' => PurchaseRequisition::STATUS_SUBMITTED]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => PurchaseRequisition::STATUS_APPROVED,
            'reviewed_by_user_id' => (string) Str::uuid7(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);
    }
}
