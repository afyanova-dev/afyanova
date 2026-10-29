<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Database\Factories;

use App\Domains\CareCoordination\Domain\Model\ReferralFeedback;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReferralFeedback>
 */
class ReferralFeedbackFactory extends Factory
{
    protected $model = ReferralFeedback::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'referral_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'kind' => null,
            'received_at' => null,
            'received_via' => null,
            'summary' => null,
            'outcome' => null,
            'status' => ReferralFeedback::STATUS_EXPECTED,
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

    public function forReferral(string $referralId): static
    {
        return $this->state(['referral_id' => $referralId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
