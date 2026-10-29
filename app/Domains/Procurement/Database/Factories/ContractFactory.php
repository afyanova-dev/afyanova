<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\Contract;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'contract_number' => 'CON-'.fake()->unique()->numerify('########'),
            'supplier_id' => (string) Str::uuid7(),
            'title' => 'Annual supply agreement',
            'reason' => null,
            'effective_date' => CarbonImmutable::now()->subMonth()->toDateString(),
            'expiry_date' => CarbonImmutable::now()->addYear()->toDateString(),
            'terms' => null,
            'currency' => 'TZS',
            'status' => Contract::STATUS_DRAFT,
            'supersedes_contract_id' => null,
            'superseded_at' => null,
            'superseded_by_user_id' => null,
            'amendment_count' => 0,
            'termination_reason' => null,
            'activated_by_user_id' => null,
            'activated_at' => null,
            'terminated_by_user_id' => null,
            'terminated_at' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => CarbonImmutable::now(),
            'recorded_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function active(): static
    {
        return $this->state([
            'status' => Contract::STATUS_ACTIVE,
            'activated_by_user_id' => (string) Str::uuid7(),
            'activated_at' => CarbonImmutable::now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state([
            'status' => Contract::STATUS_ACTIVE,
            'effective_date' => CarbonImmutable::now()->subYears(2)->toDateString(),
            'expiry_date' => CarbonImmutable::now()->subMonth()->toDateString(),
        ]);
    }
}
