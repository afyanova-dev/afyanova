<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\ItemBillingMap;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ItemBillingMap>
 */
class ItemBillingMapFactory extends Factory
{
    protected $model = ItemBillingMap::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'inv_item_id' => (string) Str::uuid7(),
            'billing_service_id' => (string) Str::uuid7(),
            'active' => true,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forItem(string $invItemId): static
    {
        return $this->state(['inv_item_id' => $invItemId]);
    }

    public function toService(string $billingServiceId): static
    {
        return $this->state(['billing_service_id' => $billingServiceId]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }
}
