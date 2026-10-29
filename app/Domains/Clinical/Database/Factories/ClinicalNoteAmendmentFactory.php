<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Enum\ClinicalNoteAmendmentType;
use App\Domains\Clinical\Domain\Model\ClinicalNoteAmendment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalNoteAmendment>
 */
class ClinicalNoteAmendmentFactory extends Factory
{
    protected $model = ClinicalNoteAmendment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'clinical_note_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'amendment_type' => ClinicalNoteAmendmentType::Addendum,
            'body' => fake()->sentence(),
            'reason' => 'clarification requested at review',
            'author_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forNote(string $clinicalNoteId): static
    {
        return $this->state(['clinical_note_id' => $clinicalNoteId]);
    }
}
