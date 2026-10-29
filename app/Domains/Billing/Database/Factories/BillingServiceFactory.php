<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\BillingService;
use App\Domains\Billing\Domain\ValueObject\BillingServiceStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BillingService>
 */
class BillingServiceFactory extends Factory
{
    protected $model = BillingService::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'SVC-'.Str::upper(Str::random(6)),
            'name' => $this->faker->words(3, true),
            'category' => 'CONSULTATION',
            'status' => BillingServiceStatus::ACTIVE,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function category(string $category): static
    {
        return $this->state(['category' => $category]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => BillingServiceStatus::INACTIVE]);
    }
}
