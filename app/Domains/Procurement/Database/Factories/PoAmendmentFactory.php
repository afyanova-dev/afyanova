<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\PoAmendment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PoAmendment>
 */
class PoAmendmentFactory extends Factory
{
    protected $model = PoAmendment::class;

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
            'sequence' => 1,
            'amendment_type' => PoAmendment::TYPE_QUANTITY,
            'po_line_id' => (string) Str::uuid7(),
            'field_changed' => 'ordered_qty',
            'previous_value' => '10',
            'new_value' => '15',
            'reason' => 'Ward demand increased',
            'amended_by_user_id' => (string) Str::uuid7(),
            'amended_at' => CarbonImmutable::now(),
            'version' => 0,
            'created_at' => CarbonImmutable::now(),
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
