<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\InvoiceLine;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InvoiceLine>
 */
class InvoiceLineFactory extends Factory
{
    protected $model = InvoiceLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'invoice_id' => (string) Str::uuid7(),
            'charge_id' => (string) Str::uuid7(),
            'description' => 'Consultation service',
            'billing_service_code' => 'CONSULT',
            'quantity' => '1.0000',
            'unit_price' => '5000.00',
            'amount' => '5000.00',
            'line_active' => true,
            'service_category' => 'CONSULTATION',
            'payer_responsibility' => null,
            'patient_responsibility' => null,
            'coverage_status' => null,
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
