<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Database\Factories;

use App\Domains\Clinical\Domain\Enum\ClinicalNoteStatus;
use App\Domains\Clinical\Domain\Model\ClinicalNote;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ClinicalNote>
 */
class ClinicalNoteFactory extends Factory
{
    protected $model = ClinicalNote::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'author_user_id' => (string) Str::uuid7(),
            'authored_context' => ClinicalNote::AUTHORED_CONTEXT_CLINICIAN,
            'note_type' => 'progress_note',
            'title' => 'Progress note',
            'body' => fake()->paragraph(),
            'status' => ClinicalNoteStatus::Draft,
            'signed_at' => null,
            'signed_by_user_id' => null,
            'amendment_count' => 0,
            'occurred_at' => now(),
            'recorded_at' => now(),
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

    public function forEncounter(string $encounterId): static
    {
        return $this->state(['encounter_id' => $encounterId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function authoredBy(string $userId): static
    {
        return $this->state(['author_user_id' => $userId]);
    }

    /** A nursing-authored note — `authored_context = NURSING` (ADR-144, increment C.8). */
    public function nursing(): static
    {
        return $this->state(['authored_context' => ClinicalNote::AUTHORED_CONTEXT_NURSING]);
    }

    /** A DRAFT note — created, not yet attested (ADR-134). */
    public function draft(): static
    {
        return $this->state([
            'status' => ClinicalNoteStatus::Draft,
            'signed_at' => null,
            'signed_by_user_id' => null,
        ]);
    }

    /** A SIGNED note — attested, body immutable (ADR-134). */
    public function signed(?string $signedByUserId = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ClinicalNoteStatus::Signed,
            'signed_at' => now()->subMinutes(5),
            'signed_by_user_id' => $signedByUserId ?? $attributes['author_user_id'] ?? (string) Str::uuid7(),
        ]);
    }

    /** A CANCELLED draft (ADR-134). */
    public function cancelled(): static
    {
        return $this->state([
            'status' => ClinicalNoteStatus::Cancelled,
            'signed_at' => null,
            'signed_by_user_id' => null,
        ]);
    }
}
