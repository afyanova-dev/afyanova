<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\FinancialAccount;
use App\Domains\Billing\Domain\ValueObject\FinancialAccountStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FinancialAccount>
 */
class FinancialAccountFactory extends Factory
{
    protected $model = FinancialAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'account_number' => 'FA-'.sprintf('%08d', $this->faker->unique()->numberBetween(1, 99999999)),
            'status' => FinancialAccountStatus::OPEN,
            'currency' => 'TZS',
            'opened_at' => $now,
            'opened_by_user_id' => (string) Str::uuid7(),
            'closed_at' => null,
            'closed_by_user_id' => null,
            'close_reason_code' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
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

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function closed(): static
    {
        return $this->state([
            'status' => FinancialAccountStatus::CLOSED,
            'closed_at' => CarbonImmutable::now(),
            'closed_by_user_id' => (string) Str::uuid7(),
            'close_reason_code' => 'ADMIN_CLOSURE',
        ]);
    }
}
