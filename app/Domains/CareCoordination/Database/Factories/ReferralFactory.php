<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Database\Factories;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Referral>
 */
class ReferralFactory extends Factory
{
    protected $model = Referral::class;

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
            'direction' => Referral::DIRECTION_OUTBOUND,
            'implies_handover' => false,
            'source_encounter_id' => null,
            'target_type' => Referral::TARGET_TYPE_INTERNAL,
            'target_ref' => (string) Str::uuid7(),
            'specialty_requested' => 'CARDIOLOGY',
            'priority' => 'ROUTINE',
            'clinical_reason_ref' => null,
            'consent_basis_ref' => null,
            'status' => Referral::STATUS_DRAFT,
            'target_encounter_id' => null,
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

    public function targetingFacility(string $facilityId): static
    {
        return $this->state(['target_type' => Referral::TARGET_TYPE_INTERNAL, 'target_ref' => $facilityId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
