<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Database\Factories;

use App\Domains\Pharmacy\Domain\Model\Pickup;
use App\Domains\Pharmacy\Domain\ValueObject\PickupStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Pickup>
 */
class PickupFactory extends Factory
{
    protected $model = Pickup::class;

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
            'dispensing_id' => (string) Str::uuid7(),
            'pharmacy_order_id' => (string) Str::uuid7(),
            'prescription_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'pickup_number' => 'PKP-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'status' => PickupStatus::READY_FOR_PICKUP,
            'recipient_type' => null,
            'recipient_name' => null,
            'verification_note' => null,
            'collected_by_user_id' => null,
            'collected_at' => null,
            'outcome_reason_code' => null,
            'outcome_note' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forDispensing(string $dispensingId): static
    {
        return $this->state(['dispensing_id' => $dispensingId]);
    }

    public function pickedUp(): static
    {
        return $this->state([
            'status' => PickupStatus::PICKED_UP,
            'recipient_type' => 'PATIENT',
            'collected_by_user_id' => (string) Str::uuid7(),
            'collected_at' => CarbonImmutable::now(),
        ]);
    }
}
