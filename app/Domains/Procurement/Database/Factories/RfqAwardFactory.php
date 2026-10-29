<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\RfqAward;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqAward>
 */
class RfqAwardFactory extends Factory
{
    protected $model = RfqAward::class;

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
            'rfq_line_id' => (string) Str::uuid7(),
            'quotation_id' => (string) Str::uuid7(),
            'quotation_line_id' => (string) Str::uuid7(),
            'supplier_id' => (string) Str::uuid7(),
            'awarded_qty' => '100',
            'unit_price' => '1250.00',
            'reason' => 'Lowest compliant offer with the shortest lead time',
            'decided_by_user_id' => (string) Str::uuid7(),
            'decided_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
