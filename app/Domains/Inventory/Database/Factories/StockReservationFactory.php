<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\StockReservation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<StockReservation>
 */
class StockReservationFactory extends Factory
{
    protected $model = StockReservation::class;

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
            'item_id' => (string) Str::uuid7(),
            'location_id' => (string) Str::uuid7(),
            'batch_id' => null,
            'reserved_qty' => '5',
            'consumer_type' => 'MANUAL',
            'consumer_ref' => null,
            'reason' => null,
            'hold_until' => null,
            'status' => StockReservation::STATUS_ACTIVE,
            'fulfilled_by_movement_id' => null,
            'released_reason' => null,
            'requested_by_user_id' => (string) Str::uuid7(),
            'released_by_user_id' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function lapsed(): static
    {
        return $this->state(['hold_until' => CarbonImmutable::now()->subHour()]);
    }
}
