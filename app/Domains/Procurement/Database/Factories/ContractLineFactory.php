<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\ContractLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContractLine>
 */
class ContractLineFactory extends Factory
{
    protected $model = ContractLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_id' => (string) Str::uuid7(),
            'line_no' => 1,
            'line_type' => ContractLine::LINE_TYPE_SERVICE,
            'item_ref' => null,
            'item_code' => null,
            'item_name' => null,
            'description' => 'Analyser service contract',
            'agreed_unit_price' => '1000.00',
            'quantity_ceiling' => '100.0000',
            'uom' => 'EACH',
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
