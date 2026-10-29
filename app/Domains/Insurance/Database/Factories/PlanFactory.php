<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\Plan;
use App\Domains\Insurance\Domain\ValueObject\PlanStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Plan>
 */
class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'payer_id' => (string) Str::uuid7(),
            'code' => 'PLN-'.Str::upper(Str::random(6)),
            'name' => Str::title($this->faker->word()).' Plan',
            'status' => PlanStatus::ACTIVE,
            'status_reason' => null,
            'effective_from' => null,
            'effective_to' => null,
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

    public function forPayer(string $payerId): static
    {
        return $this->state(['payer_id' => $payerId]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => PlanStatus::INACTIVE]);
    }
}
