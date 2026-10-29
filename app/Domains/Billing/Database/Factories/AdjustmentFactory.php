<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\Adjustment;
use App\Domains\Billing\Domain\ValueObject\AdjustmentType;
use App\Domains\Billing\Domain\ValueObject\FinancialControlStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Adjustment>
 */
class AdjustmentFactory extends Factory
{
    protected $model = Adjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();
        $requester = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'financial_account_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'invoice_id' => null,
            'amount' => '500.00',
            'currency' => 'TZS',
            'adjustment_type' => AdjustmentType::GOODWILL,
            'direction' => 'CREDIT',
            'status' => FinancialControlStatus::REQUESTED,
            'note' => null,
            'dual_control' => true,
            'requested_at' => $now,
            'requested_by_user_id' => $requester,
            'approved_at' => null,
            'approved_by_user_id' => null,
            'rejected_at' => null,
            'rejected_by_user_id' => null,
            'reject_reason_code' => null,
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
