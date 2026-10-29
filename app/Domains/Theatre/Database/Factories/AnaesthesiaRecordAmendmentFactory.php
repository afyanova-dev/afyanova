<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\AnaesthesiaRecordAmendment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AnaesthesiaRecordAmendment>
 */
class AnaesthesiaRecordAmendmentFactory extends Factory
{
    protected $model = AnaesthesiaRecordAmendment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'anaesthesia_record_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'amendment_text' => 'Corrected the recorded reversal-agent dose.',
            'reason' => 'Transcription error in the original record.',
            'created_by_user_id' => (string) Str::uuid7(),
            'created_at' => now(),
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

    public function forRecord(string $anaesthesiaRecordId): static
    {
        return $this->state(['anaesthesia_record_id' => $anaesthesiaRecordId]);
    }

    public function withSequence(int $sequence): static
    {
        return $this->state(['sequence' => $sequence]);
    }
}
