<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImagingOrder>
 */
class ImagingOrderFactory extends Factory
{
    protected $model = ImagingOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'order_number' => 'RAD-'.fake()->unique()->numerify('########'),
            'priority' => 'ROUTINE',
            'clinical_indication_ref' => null,
            'clinical_indication_text' => 'Suspected pneumonia.',
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'status' => ImagingOrder::STATUS_DRAFT,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
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

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
