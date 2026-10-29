<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SurgicalSpecimen>
 */
class SurgicalSpecimenFactory extends Factory
{
    protected $model = SurgicalSpecimen::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'case_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'specimen_type' => 'TISSUE',
            'body_site' => 'APPENDIX',
            'laterality' => null,
            'description' => 'Inflamed appendix, ~8cm.',
            'collected_by_user_id' => (string) Str::uuid7(),
            'collected_at' => now(),
            'destination_laboratory' => 'HISTOPATHOLOGY',
            'lab_accession_ref' => null,
            'status' => SurgicalSpecimen::STATUS_COLLECTED,
            'handed_over_by_user_id' => null,
            'handed_over_at' => null,
            'received_recorded_at' => null,
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

    public function forCase(string $caseId): static
    {
        return $this->state(['case_id' => $caseId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
