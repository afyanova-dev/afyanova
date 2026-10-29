<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\PayerTariff;
use App\Domains\Insurance\Domain\ValueObject\PayerTariffStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayerTariff>
 */
class PayerTariffFactory extends Factory
{
    protected $model = PayerTariff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_id' => (string) Str::uuid7(),
            'payer_id' => (string) Str::uuid7(),
            'service_category' => 'CONSULTATION',
            'amount' => '80000.00',
            'currency' => 'TZS',
            'effective_from' => $now->subMonths(6)->toDateString(),
            'effective_to' => null,
            'status' => PayerTariffStatus::CURRENT,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forContract(string $contractId, string $payerId): static
    {
        return $this->state([
            'contract_id' => $contractId,
            'payer_id' => $payerId,
        ]);
    }

    public function category(string $category): static
    {
        return $this->state(['service_category' => $category]);
    }

    public function amount(string $amount): static
    {
        return $this->state(['amount' => $amount]);
    }
}
