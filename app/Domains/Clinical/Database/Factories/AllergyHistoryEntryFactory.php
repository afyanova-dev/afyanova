<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Model\AllergyHistoryEntry;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AllergyHistoryEntry>
 */
class AllergyHistoryEntryFactory extends Factory
{
    protected $model = AllergyHistoryEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'allergy_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'change_kind' => AllergyHistoryEntry::CHANGE_KIND_CONTENT,
            'change_type' => 'REVISION',
            'category' => 'DRUG',
            'substance_name' => 'Penicillin',
            'substance_code' => '764146007',
            'substance_code_system' => 'SNOMED-CT',
            'reaction_description' => 'Urticaria',
            'severity' => 'MILD',
            'clinical_status' => 'ACTIVE',
            'verification_status' => 'SUSPECTED',
            'onset_date' => null,
            'reason' => null,
            'author_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forAllergy(string $allergyId): static
    {
        return $this->state(['allergy_id' => $allergyId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }
}
