<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\QuotationRevision;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<QuotationRevision>
 */
class QuotationRevisionFactory extends Factory
{
    protected $model = QuotationRevision::class;

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
            'sequence' => 1,
            'field_changed' => 'unit_price',
            'previous_value' => '1300.00',
            'new_value' => '1250.00',
            'reason' => 'Supplier improved the price after negotiation',
            'revised_by_user_id' => (string) Str::uuid7(),
            'revised_at' => CarbonImmutable::now(),
            'version' => 0,
            'created_at' => CarbonImmutable::now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
