<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Database\Factories;

use App\Domains\CareCoordination\Domain\Model\FollowUp;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FollowUp>
 */
class FollowUpFactory extends Factory
{
    protected $model = FollowUp::class;

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
            'source_encounter_id' => (string) Str::uuid7(),
            'due_from' => now()->addWeek()->toDateString(),
            'due_to' => now()->addWeeks(3)->toDateString(),
            'reason' => null,
            'instructions' => null,
            'booked_appointment_id' => null,
            'resulting_encounter_id' => null,
            'status' => FollowUp::STATUS_PENDING,
            'outcome_recorded_at' => null,
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
        return $this->state(['source_encounter_id' => $encounterId]);
    }

    public function due(string $from, string $to): static
    {
        return $this->state(['due_from' => $from, 'due_to' => $to]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
