<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\AddressStatus;
use App\Domains\Patient\Domain\Enum\AddressType;
use App\Domains\Patient\Domain\Model\PatientAddress;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientAddress>
 */
class PatientAddressFactory extends Factory
{
    protected $model = PatientAddress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => (string) Str::uuid7(),
            'type' => AddressType::Residential,
            'region' => 'Dar es Salaam',
            'district' => 'Kinondoni',
            'ward' => 'Mikocheni',
            'street_or_village' => 'Msasani',
            'postal_code' => null,
            'notes' => null,
            'status' => AddressStatus::Active,
            'recorded_by_user_id' => (string) Str::uuid7(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPatient(string $patientId): static
    {
        return $this->state(['patient_id' => $patientId]);
    }

    public function ofType(AddressType $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => AddressStatus::Inactive,
            'removed_by_user_id' => (string) Str::uuid7(),
            'removed_at' => now(),
        ]);
    }
}
