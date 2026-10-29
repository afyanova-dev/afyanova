<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\IdentifierType;
use App\Domains\Patient\Domain\Enum\IdentifierVerificationStatus;
use App\Domains\Patient\Domain\Model\PatientIdentifier;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientIdentifier>
 */
class PatientIdentifierFactory extends Factory
{
    protected $model = PatientIdentifier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = (string) fake()->unique()->numerify('##################');

        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => (string) Str::uuid7(),
            'identifier_type' => IdentifierType::NationalId,
            'value' => $value,
            'normalized_value' => PatientIdentifier::normalize($value),
            'issuing_authority' => null,
            'verification_status' => IdentifierVerificationStatus::Unverified,
            'verified_at' => null,
            'verified_by_user_id' => null,
            'verification_source' => null,
            'effective_from' => null,
            'effective_to' => null,
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

    public function ofType(IdentifierType $type): static
    {
        return $this->state(['identifier_type' => $type]);
    }

    /**
     * @param  non-empty-string  $value
     */
    public function withValue(string $value): static
    {
        return $this->state([
            'value' => $value,
            'normalized_value' => PatientIdentifier::normalize($value),
        ]);
    }

    public function verified(): static
    {
        return $this->state([
            'verification_status' => IdentifierVerificationStatus::Verified,
            'verified_at' => now(),
            'verified_by_user_id' => (string) Str::uuid7(),
            'verification_source' => 'SEED',
        ]);
    }
}
