<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\BillableEvent;
use App\Domains\Billing\Domain\ValueObject\BillableEventStatus;
use App\Domains\Billing\Domain\ValueObject\BillablePendingReason;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillableEvent>
 */
class BillableEventFactory extends Factory
{
    protected $model = BillableEvent::class;

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
            'source_domain' => 'PHARMACY',
            'source_event_type' => 'MedicationDispensed',
            'source_event_id' => (string) Str::uuid7(),
            'source_ref' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'item_id' => (string) Str::uuid7(),
            'quantity' => '10.0000',
            'occurred_at' => $now,
            'status' => BillableEventStatus::PENDING,
            'pending_reason' => BillablePendingReason::ITEM_UNMAPPED,
            'charge_id' => null,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function charged(string $chargeId): static
    {
        return $this->state([
            'status' => BillableEventStatus::CHARGED,
            'pending_reason' => null,
            'charge_id' => $chargeId,
        ]);
    }
}
