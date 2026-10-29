<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Receipt;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Billing\Domain\ValueObject\ReceiptStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

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
            'payment_id' => (string) Str::uuid7(),
            'receipt_number' => 'RCT-'.sprintf('%08d', random_int(1, 99999999)),
            'method' => PaymentMethod::CASH,
            'amount' => '5000.00',
            'currency' => 'TZS',
            'status' => ReceiptStatus::ISSUED,
            'issued_at' => $now,
            'issued_by_user_id' => (string) Str::uuid7(),
            'reversed_at' => null,
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
