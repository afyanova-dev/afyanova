<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\ContactStatus;
use App\Domains\Patient\Domain\Enum\ContactType;
use App\Domains\Patient\Domain\Model\PatientContact;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PatientContact>
 */
class PatientContactFactory extends Factory
{
    protected $model = PatientContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $value = fake()->numerify('+2557########');

        return [
            'tenant_id' => Tenant::factory(),
            'patient_id' => (string) Str::uuid7(),
            'type' => ContactType::Mobile,
            'value' => $value,
            'normalized_value' => PatientContact::normalize(ContactType::Mobile, $value),
            'label' => 'secondary',
            'is_preferred' => false,
            'status' => ContactStatus::Active,
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

    public function ofType(ContactType $type): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => $type,
            'normalized_value' => PatientContact::normalize($type, (string) $attributes['value']),
        ]);
    }

    public function inactive(): static
    {
        return $this->state([
            'status' => ContactStatus::Inactive,
            'removed_by_user_id' => (string) Str::uuid7(),
            'removed_at' => now(),
        ]);
    }
}
