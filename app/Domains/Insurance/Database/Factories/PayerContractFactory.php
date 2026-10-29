<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\PayerContract;
use App\Domains\Insurance\Domain\ValueObject\PayerContractStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PayerContract>
 */
class PayerContractFactory extends Factory
{
    protected $model = PayerContract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_number' => 'PCT-'.sprintf('%08d', fake()->unique()->numberBetween(1, 99999999)),
            'payer_id' => (string) Str::uuid7(),
            'name' => fake()->company().' Provider Agreement',
            'reference' => 'EXT-'.fake()->numerify('####'),
            'facility_id' => null,
            'status' => PayerContractStatus::ACTIVE,
            'effective_from' => $now->subMonths(6)->toDateString(),
            'effective_to' => $now->addMonths(6)->toDateString(),
            'terms' => 'Standard terms apply.',
            'termination_reason' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'activated_by_user_id' => (string) Str::uuid7(),
            'activated_at' => $now->subMonths(6),
            'terminated_by_user_id' => null,
            'terminated_at' => null,
            'expired_at' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forPayer(string $payerId): static
    {
        return $this->state(['payer_id' => $payerId]);
    }

    public function draft(): static
    {
        return $this->state([
            'status' => PayerContractStatus::DRAFT,
            'activated_by_user_id' => null,
            'activated_at' => null,
        ]);
    }

    public function active(): static
    {
        return $this->state(['status' => PayerContractStatus::ACTIVE]);
    }

    public function terminated(?string $reason = 'Cancelled by provider'): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => PayerContractStatus::TERMINATED,
            'terminated_by_user_id' => (string) Str::uuid7(),
            'terminated_at' => $now,
            'termination_reason' => $reason,
        ]);
    }

    public function expired(): static
    {
        $now = CarbonImmutable::now();

        return $this->state([
            'status' => PayerContractStatus::EXPIRED,
            'expired_at' => $now,
            'effective_to' => $now->subDay()->toDateString(),
        ]);
    }
}
