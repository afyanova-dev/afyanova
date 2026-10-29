<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Database\Factories;

use App\Domains\Pharmacy\Domain\Model\Dispensing;
use App\Domains\Pharmacy\Domain\ValueObject\DispensingStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Dispensing>
 */
class DispensingFactory extends Factory
{
    protected $model = Dispensing::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'pharmacy_order_id' => (string) Str::uuid7(),
            'prescription_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'dispensing_number' => 'DSP-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'item_id' => (string) Str::uuid7(),
            'location_id' => (string) Str::uuid7(),
            'batch_number' => null,
            'quantity' => '10',
            'quantity_unit' => null,
            'authorized_quantity_value' => null,
            'authorized_quantity_unit' => null,
            'dispensed_without_ceiling' => true,
            'reservation_id' => (string) Str::uuid7(),
            'movement_id' => (string) Str::uuid7(),
            'financial_clearance_ref' => null,
            'dispensed_by_user_id' => (string) Str::uuid7(),
            'status' => DispensingStatus::COMPLETED,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function forOrder(string $pharmacyOrderId): static
    {
        return $this->state(['pharmacy_order_id' => $pharmacyOrderId]);
    }

    public function withAuthorizedQuantity(string $value, string $unit): static
    {
        return $this->state([
            'authorized_quantity_value' => $value,
            'authorized_quantity_unit' => $unit,
            'dispensed_without_ceiling' => false,
        ]);
    }
}
