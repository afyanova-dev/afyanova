<?php

declare(strict_types=1);

namespace App\Domains\Patient\Database\Factories;

use App\Domains\Patient\Domain\Enum\DobPrecision;
use App\Domains\Patient\Domain\Enum\PatientStatus;
use App\Domains\Patient\Domain\Enum\Sex;
use App\Domains\Patient\Domain\Model\Patient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $given = fake()->firstName();
        $family = fake()->lastName();

        return [
            'tenant_id' => Tenant::factory(),
            'patient_number' => 'PAT-'.fake()->unique()->numerify('########'),
            'given_name' => $given,
            'family_name' => $family,
            'middle_name' => null,
            'display_name' => "{$given} {$family}",
            'sex' => fake()->randomElement(Sex::cases()),
            'date_of_birth' => fake()->date(),
            'dob_precision' => DobPrecision::Known,
            'primary_phone' => null,
            'email' => null,
            'status' => PatientStatus::Active,
            'is_provisional' => false,
            'registration_source' => 'RECEPTION',
            'registered_by_user_id' => null,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => PatientStatus::Inactive]);
    }

    public function deceased(): static
    {
        return $this->state(['status' => PatientStatus::Deceased]);
    }

    public function provisional(): static
    {
        return $this->state(['is_provisional' => true]);
    }
}
