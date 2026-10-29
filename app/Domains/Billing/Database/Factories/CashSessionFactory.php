<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\CashSession;
use App\Domains\Billing\Domain\ValueObject\CashSessionStatus;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CashSession>
 */
class CashSessionFactory extends Factory
{
    protected $model = CashSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();
        $cashier = (string) Str::uuid7();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'cashier_user_id' => $cashier,
            'opening_float' => '100000.00',
            'currency' => 'TZS',
            'status' => CashSessionStatus::OPEN,
            'expected_cash' => null,
            'counted_cash' => null,
            'variance' => null,
            'opened_at' => $now,
            'opened_by_user_id' => $cashier,
            'closed_at' => null,
            'closed_by_user_id' => null,
            'close_note' => null,
            'reconciled_at' => null,
            'reconciled_by_user_id' => null,
            'variance_approved_by_user_id' => null,
            'variance_note' => null,
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
