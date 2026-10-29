<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\Batch;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'item_id' => (string) Str::uuid7(),
            'batch_number' => 'BN-'.Str::upper(Str::random(6)),
            'manufacture_date' => null,
            'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'supplier_ref' => null,
            'received_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forItem(string $itemId): static
    {
        return $this->state(['item_id' => $itemId]);
    }
}
