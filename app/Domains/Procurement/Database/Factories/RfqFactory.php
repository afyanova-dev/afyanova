<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\Rfq;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Rfq>
 */
class RfqFactory extends Factory
{
    protected $model = Rfq::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'rfq_number' => 'RFQ-'.fake()->unique()->numerify('########'),
            'requisition_id' => null,
            'title' => 'Gloves & syringes — Q3 restock',
            'reason' => null,
            'required_date' => null,
            'closing_date' => null,
            'terms' => null,
            'status' => Rfq::STATUS_DRAFT,
            'cancellation_reason' => null,
            'awarded_by_user_id' => null,
            'awarded_at' => null,
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

    public function issued(): static
    {
        return $this->state(['status' => Rfq::STATUS_ISSUED]);
    }

    public function closed(): static
    {
        return $this->state(['status' => Rfq::STATUS_CLOSED]);
    }
}
