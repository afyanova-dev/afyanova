<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\PoReceipt;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PoReceipt>
 */
class PoReceiptFactory extends Factory
{
    protected $model = PoReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'po_id' => (string) Str::uuid7(),
            'po_line_id' => (string) Str::uuid7(),
            'source_event_id' => (string) Str::uuid7(),
            'movement_id' => (string) Str::uuid7(),
            'movement_number' => 'MOV-'.fake()->unique()->numerify('########'),
            'item_ref' => (string) Str::uuid7(),
            'batch_ref' => null,
            'location_ref' => (string) Str::uuid7(),
            'quantity' => '10',
            'uom' => 'BOX',
            'source_type' => 'PROCUREMENT',
            'received_at' => CarbonImmutable::now(),
            'recorded_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
