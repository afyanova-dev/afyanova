<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\ContractAmendment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContractAmendment>
 */
class ContractAmendmentFactory extends Factory
{
    protected $model = ContractAmendment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_id' => (string) Str::uuid7(),
            'sequence' => 1,
            'amendment_type' => ContractAmendment::TYPE_CEILING,
            'contract_line_id' => (string) Str::uuid7(),
            'field_changed' => 'quantity_ceiling',
            'previous_value' => '100.0000',
            'new_value' => '250.0000',
            'reason' => 'Framework volume increased for the year',
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
