<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Payment;
use App\Domains\Billing\Domain\ValueObject\PaymentMethod;
use App\Domains\Billing\Domain\ValueObject\PaymentStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

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
            'method' => PaymentMethod::CASH,
            'amount' => '5000.00',
            'currency' => 'TZS',
            'status' => PaymentStatus::RECORDED,
            'payment_reference' => null,
            'collected_at_facility_id' => (string) Str::uuid7(),
            'collected_by_user_id' => (string) Str::uuid7(),
            'cash_session_id' => null,
            'received_at' => $now,
            'reversal_id' => null,
            'reversed_at' => null,
            'reversed_by_user_id' => null,
            'reverse_reason_code' => null,
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
