<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Database\Factories;

use App\Domains\Encounter\Domain\Enum\EncounterDisposition;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\Encounter\Domain\Enum\EncounterType;
use App\Domains\Encounter\Domain\Model\Encounter;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    protected $model = Encounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_number' => 'ENC-'.fake()->unique()->numerify('########'),
            'encounter_type' => EncounterType::Outpatient,
            'status' => EncounterStatus::Arrived,
            'disposition' => null,
            'department_id' => null,
            'unit_id' => null,
            'arrived_at' => now(),
            'started_at' => null,
            'admitted_at' => null,
            'completed_at' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'created_source' => 'RECEPTION',
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

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function ofType(EncounterType $type): static
    {
        return $this->state(['encounter_type' => $type]);
    }

    public function withStatus(EncounterStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** A pre-created PLANNED encounter — no arrival recorded yet (ADR-121). */
    public function planned(): static
    {
        return $this->state([
            'status' => EncounterStatus::Planned,
            'arrived_at' => null,
        ]);
    }

    /** An ARRIVED encounter — checked in / arrival recorded, care not yet started (ADR-121). */
    public function arrived(): static
    {
        return $this->state([
            'status' => EncounterStatus::Arrived,
            'arrived_at' => now(),
            'started_at' => null,
        ]);
    }

    /** An ACTIVE encounter — care in progress, not yet admitted or completed (ADR-121). */
    public function active(): static
    {
        return $this->state([
            'status' => EncounterStatus::Active,
            'arrived_at' => now()->subMinutes(30),
            'started_at' => now()->subMinutes(15),
            'admitted_at' => null,
            'completed_at' => null,
            'disposition' => null,
        ]);
    }

    /** A pre-existing disposition — for the E.5 revise path (ADR-130 A). */
    public function withDisposition(EncounterDisposition $disposition): static
    {
        return $this->state(['disposition' => $disposition]);
    }

    /** A pre-existing coarse care-context location — for the E.6 move path (ADR-130 B). */
    public function withLocation(?string $departmentId, ?string $unitId): static
    {
        return $this->state(['department_id' => $departmentId, 'unit_id' => $unitId]);
    }

    /** A COMPLETED encounter — the attendance is closed, disposition recorded (ADR-121). */
    public function completed(EncounterDisposition $disposition = EncounterDisposition::Discharged): static
    {
        return $this->state([
            'status' => EncounterStatus::Completed,
            'arrived_at' => now()->subMinutes(45),
            'started_at' => now()->subMinutes(30),
            'completed_at' => now()->subMinutes(5),
            'disposition' => $disposition,
        ]);
    }
}
