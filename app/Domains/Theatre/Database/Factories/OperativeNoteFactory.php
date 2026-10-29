<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\OperativeNote;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperativeNote>
 */
class OperativeNoteFactory extends Factory
{
    protected $model = OperativeNote::class;

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
            'content' => [],
            'status' => OperativeNote::STATUS_DRAFT,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'signed_by_user_id' => null,
            'signed_at' => null,
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

    public function forCase(string $caseId): static
    {
        return $this->state(['case_id' => $caseId]);
    }

    public function forEncounter(string $encounterId, string $patientId): static
    {
        return $this->state([
            'encounter_id' => $encounterId,
            'patient_id' => $patientId,
        ]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function withContent(array $content): static
    {
        return $this->state(['content' => $content]);
    }

    public function reviewed(string $reviewerUserId): static
    {
        return $this->state([
            'status' => OperativeNote::STATUS_REVIEWED,
            'reviewed_by_user_id' => $reviewerUserId,
            'reviewed_at' => now(),
        ]);
    }

    public function signed(string $signerUserId): static
    {
        return $this->state([
            'status' => OperativeNote::STATUS_SIGNED,
            'signed_by_user_id' => $signerUserId,
            'signed_at' => now(),
        ]);
    }
}
