<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Database\Factories;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Prescription>
 */
class PrescriptionFactory extends Factory
{
    protected $model = Prescription::class;

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
            'prescribing_decision_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'prescription_number' => 'RX-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'author_user_id' => (string) Str::uuid7(),
            'change_type' => null,
            'medication_code_system' => 'RxNorm',
            'route' => 'ORAL',
            'urgency' => 'ROUTINE',
            'medication_name' => null,
            'medication_code' => null,
            'dose' => null,
            'frequency' => null,
            'duration' => null,
            'prescribed_quantity' => null,
            'dosing_instruction' => null,
            'status' => PrescriptionStatus::RECEIVED,
            'accepted_by_user_id' => null,
            'cancelled_by_user_id' => null,
            'cancel_reason' => null,
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

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function active(): static
    {
        return $this->state([
            'status' => PrescriptionStatus::ACTIVE,
            'accepted_by_user_id' => (string) Str::uuid7(),
            'medication_name' => 'Amoxicillin',
            'medication_code' => '500123',
            'dose' => '500 mg',
            'frequency' => 'TDS',
            'duration' => '7 days',
            'prescribed_quantity' => '21 capsules',
            'dosing_instruction' => 'Take with food',
        ]);
    }

    public function cancelled(string $reason = 'Entered in error'): static
    {
        return $this->state([
            'status' => PrescriptionStatus::CANCELLED,
            'cancelled_by_user_id' => (string) Str::uuid7(),
            'cancel_reason' => $reason,
        ]);
    }
}
