<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\RequisitionLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RequisitionLine>
 */
class RequisitionLineFactory extends Factory
{
    protected $model = RequisitionLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'requisition_id' => (string) Str::uuid7(),
            'line_no' => 1,
            'line_type' => RequisitionLine::LINE_TYPE_STOCK,
            'item_ref' => (string) Str::uuid7(),
            'item_code' => 'ITEM-'.Str::upper(Str::random(6)),
            'item_name' => 'Examination gloves (box of 100)',
            'description' => null,
            'quantity' => '10',
            'uom' => 'BOX',
            'required_date' => null,
            'status' => RequisitionLine::STATUS_OPEN,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function service(): static
    {
        return $this->state([
            'line_type' => RequisitionLine::LINE_TYPE_SERVICE,
            'item_ref' => null,
            'item_code' => null,
            'item_name' => null,
            'description' => 'Calibration of the biochemistry analyser',
        ]);
    }
}
