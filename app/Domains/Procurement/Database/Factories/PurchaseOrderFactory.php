<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'po_number' => 'PO-'.fake()->unique()->numerify('########'),
            'supplier_id' => (string) Str::uuid7(),
            'requisition_id' => null,
            'currency' => 'TZS',
            'priority' => 'ROUTINE',
            'emergency' => false,
            'emergency_reason' => null,
            'status' => PurchaseOrder::STATUS_DRAFT,
            'notes' => null,
            'approved_by_user_id' => null,
            'approved_at' => null,
            'issued_by_user_id' => null,
            'issued_at' => null,
            'cancellation_reason' => null,
            'amendment_count' => 0,
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
        return $this->state(['status' => PurchaseOrder::STATUS_SUBMITTED]);
    }

    public function approved(): static
    {
        return $this->state([
            'status' => PurchaseOrder::STATUS_APPROVED,
            'approved_by_user_id' => (string) Str::uuid7(),
            'approved_at' => CarbonImmutable::now(),
        ]);
    }

    public function issued(): static
    {
        return $this->state([
            'status' => PurchaseOrder::STATUS_ISSUED,
            'approved_by_user_id' => (string) Str::uuid7(),
            'approved_at' => CarbonImmutable::now(),
            'issued_by_user_id' => (string) Str::uuid7(),
            'issued_at' => CarbonImmutable::now(),
        ]);
    }
}
