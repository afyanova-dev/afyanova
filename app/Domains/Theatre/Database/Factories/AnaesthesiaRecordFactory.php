<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\AnaesthesiaRecord;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnaesthesiaRecord>
 */
class AnaesthesiaRecordFactory extends Factory
{
    protected $model = AnaesthesiaRecord::class;

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
            'anaesthesia_type' => 'GENERAL',
            'provider_user_id' => null,
            'started_at' => null,
            'ended_at' => null,
            'content' => [],
            'outcome' => null,
            'status' => AnaesthesiaRecord::STATUS_DRAFT,
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

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function signed(string $signerUserId): static
    {
        return $this->state([
            'status' => AnaesthesiaRecord::STATUS_SIGNED,
            'signed_by_user_id' => $signerUserId,
            'signed_at' => now(),
        ]);
    }
}
