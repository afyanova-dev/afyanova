<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\Quotation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Quotation>
 */
class QuotationFactory extends Factory
{
    protected $model = Quotation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'quotation_number' => 'QUO-'.fake()->unique()->numerify('########'),
            'rfq_id' => (string) Str::uuid7(),
            'supplier_id' => (string) Str::uuid7(),
            'supplier_quote_ref' => null,
            'currency' => 'TZS',
            'validity_date' => null,
            'lead_time_days' => 14,
            'payment_terms' => 'NET_30',
            'delivery_terms' => null,
            'notes' => null,
            'status' => Quotation::STATUS_RECEIVED,
            'revision_count' => 0,
            'received_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => CarbonImmutable::now(),
            'recorded_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function submitted(): static
    {
        return $this->state(['status' => Quotation::STATUS_SUBMITTED]);
    }
}
