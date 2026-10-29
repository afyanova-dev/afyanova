<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Database\Factories;

use App\Domains\Pharmacy\Domain\Model\DispensingReversal;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DispensingReversal>
 */
class DispensingReversalFactory extends Factory
{
    protected $model = DispensingReversal::class;

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
            'reversal_number' => 'DRV-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'item_id' => (string) Str::uuid7(),
            'location_id' => (string) Str::uuid7(),
            'batch_number' => null,
            'quantity' => '10',
            'reversal_movement_id' => (string) Str::uuid7(),
            'reason_code' => 'DISPENSING_ERROR',
            'reason_note' => null,
            'reversed_by_user_id' => (string) Str::uuid7(),
            'status' => DispensingReversal::STATUS_COMPLETED,
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
}
