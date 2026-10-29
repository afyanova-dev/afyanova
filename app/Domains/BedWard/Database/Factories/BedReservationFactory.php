<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Database\Factories;

use App\Domains\BedWard\Domain\Model\BedReservation;
use App\Models\Tenant;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BedReservation>
 */
class BedReservationFactory extends Factory
{
    protected $model = BedReservation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'bed_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'requested_by_user_id' => (string) Str::uuid7(),
            'reason' => 'ADMISSION',
            'hold_until' => $now->copy()->addHours(4),
            'status' => BedReservation::STATUS_REQUESTED,
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

    public function forBed(string $bedId): static
    {
        return $this->state(['bed_id' => $bedId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function holdingUntil(DateTimeInterface $holdUntil): static
    {
        return $this->state(['hold_until' => $holdUntil]);
    }
}
