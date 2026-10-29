<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\RfqLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqLine>
 */
class RfqLineFactory extends Factory
{
    protected $model = RfqLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'rfq_id' => (string) Str::uuid7(),
            'line_no' => 1,
            'line_type' => RfqLine::LINE_TYPE_STOCK,
            'item_ref' => (string) Str::uuid7(),
            'item_code' => 'ITEM-'.Str::upper(Str::random(6)),
            'item_name' => 'Examination gloves (box of 100)',
            'description' => null,
            'quantity' => '100',
            'uom' => 'BOX',
            'required_date' => null,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
