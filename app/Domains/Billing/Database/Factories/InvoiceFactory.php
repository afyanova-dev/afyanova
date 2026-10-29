<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Invoice;
use App\Domains\Billing\Domain\ValueObject\InvoiceStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'financial_account_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'invoice_number' => null,
            'payer_type' => Invoice::PAYER_SELF_PAY,
            'status' => InvoiceStatus::DRAFT,
            'gross' => '0.00',
            'discount' => '0.00',
            'tax' => '0.00',
            'net' => '0.00',
            'currency' => 'TZS',
            'payer_id' => null,
            'member_coverage_id' => null,
            'coverage_plan_id' => null,
            'payer_responsibility' => '0.00',
            'patient_responsibility' => '0.00',
            'coverage_status' => null,
            'coverage_source' => null,
            'coverage_confidence' => null,
            'coverage_evaluated_at' => null,
            'issued_at' => null,
            'issued_by_user_id' => null,
            'voided_at' => null,
            'voided_by_user_id' => null,
            'void_reason_code' => null,
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
