<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Database\Factories;

use App\Domains\Pharmacy\Domain\Model\PharmacyOrder;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PharmacyOrder>
 */
class PharmacyOrderFactory extends Factory
{
    protected $model = PharmacyOrder::class;

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
            'prescription_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'order_number' => 'RXO-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'dispensing_location_id' => null,
            'priority' => 'ROUTINE',
            'status' => PharmacyOrderStatus::RECEIVED,
            'validated_by_user_id' => null,
            'reject_reason_code' => null,
            'hold_reason_code' => null,
            'cancel_reason_code' => null,
            'reason_note' => null,
            'cancelled_by_user_id' => null,
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

    public function forPrescription(string $prescriptionId): static
    {
        return $this->state(['prescription_id' => $prescriptionId]);
    }

    public function validated(): static
    {
        return $this->state([
            'status' => PharmacyOrderStatus::VALIDATED,
            'validated_by_user_id' => (string) Str::uuid7(),
        ]);
    }
}
