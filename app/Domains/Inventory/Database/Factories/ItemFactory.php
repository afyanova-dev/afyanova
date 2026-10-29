<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Database\Factories;

use App\Domains\Inventory\Domain\Model\Item;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'ITEM-'.Str::upper(Str::random(6)),
            'name' => 'Paracetamol 500mg tablet',
            'description' => null,
            'category' => 'ANALGESIC',
            'item_type' => 'MEDICATION',
            'uom' => 'TABLET',
            'pack_unit' => null,
            'pack_size' => null,
            'track_batch' => false,
            'track_serial' => false,
            'track_expiry' => false,
            'is_controlled' => false,
            'active' => true,
            'version' => 1,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function batchTracked(): static
    {
        return $this->state(['track_batch' => true]);
    }

    public function expiryTracked(): static
    {
        return $this->state(['track_batch' => true, 'track_expiry' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['active' => false]);
    }

    public function controlled(): static
    {
        return $this->state(['is_controlled' => true]);
    }

    /**
     * @param  non-empty-string  $unit
     * @param  numeric-string  $size
     */
    public function packed(string $unit = 'BOX', string $size = '100'): static
    {
        return $this->state(['pack_unit' => $unit, 'pack_size' => $size]);
    }
}
