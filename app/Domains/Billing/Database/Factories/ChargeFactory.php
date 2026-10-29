<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Charge;
use App\Domains\Billing\Domain\ValueObject\ChargeStatus;
use App\Domains\Billing\Domain\ValueObject\TariffStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

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
            'financial_account_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'billing_service_id' => (string) Str::uuid7(),
            'billing_service_code' => 'MED-GENERIC',
            'billable_event_id' => (string) Str::uuid7(),
            'source_ref' => (string) Str::uuid7(),
            'quantity' => '10.0000',
            'unit_price' => '500.00',
            'tariff_row_id' => (string) Str::uuid7(),
            'resolved_scope' => TariffStatus::SCOPE_TENANT_DEFAULT,
            'amount' => '5000.00',
            'currency' => 'TZS',
            'status' => ChargeStatus::POSTED,
            'reversal_id' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
