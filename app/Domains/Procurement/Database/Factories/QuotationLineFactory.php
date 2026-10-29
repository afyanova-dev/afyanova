<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\QuotationLine;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuotationLine>
 */
class QuotationLineFactory extends Factory
{
    protected $model = QuotationLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'quotation_id' => (string) Str::uuid7(),
            'rfq_line_id' => (string) Str::uuid7(),
            'line_no' => 1,
            'unit_price' => '1250.00',
            'offered_qty' => '100',
            'tax_amount' => null,
            'discount_amount' => null,
            'line_note' => null,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
