<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Tariff;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tariff>
 */
class TariffFactory extends Factory
{
    protected $model = Tariff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'billing_service_id' => (string) Str::uuid7(),
            'facility_id' => (string) Str::uuid7(),
            'amount' => '5000.00',
            'currency' => 'TZS',
            'effective_from' => $now->subMonth()->toDateString(),
            'effective_to' => null,
            'status' => TariffStatus::CURRENT,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forService(string $billingServiceId): static
    {
        return $this->state(['billing_service_id' => $billingServiceId]);
    }

    public function scope(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }
}
