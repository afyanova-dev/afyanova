<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SurgicalCase>
 */
class SurgicalCaseFactory extends Factory
{
    protected $model = SurgicalCase::class;

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
            'operating_room_id' => null,
            'case_number' => 'SURG-'.fake()->unique()->numerify('########'),
            'priority' => 'ELECTIVE',
            'scheduled_for' => null,
            'planned_procedure' => 'Appendectomy',
            'actual_procedure' => null,
            'indication' => 'Suspected appendicitis',
            'findings' => null,
            'outcome' => null,
            'complications' => null,
            'safety_checklist' => [],
            'recovery_destination_type' => null,
            'status' => SurgicalCase::STATUS_REQUESTED,
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

    public function forEncounter(string $encounterId, string $patientId): static
    {
        return $this->state([
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
        ]);
    }

    public function inOperatingRoom(string $operatingRoomId): static
    {
        return $this->state(['operating_room_id' => $operatingRoomId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * @param  list<array{stage: string, completed_at: string, actor_user_id: string}>  $checklist
     */
    public function withChecklist(array $checklist): static
    {
        return $this->state(['safety_checklist' => $checklist]);
    }
}
