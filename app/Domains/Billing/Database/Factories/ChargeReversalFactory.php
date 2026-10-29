<?php

declare(strict_types=1);

namespace App\Domains\Billing\Database\Factories;

use App\Domains\Billing\Domain\Model\ChargeReversal;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChargeReversal>
 */
class ChargeReversalFactory extends Factory
{
    protected $model = ChargeReversal::class;

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
            'charge_id' => (string) Str::uuid7(),
            'financial_account_id' => (string) Str::uuid7(),
            'source_reversal_event_id' => (string) Str::uuid7(),
            'dispensing_id' => (string) Str::uuid7(),
            'amount' => '5000.00',
            'currency' => 'TZS',
            'reason_code' => 'DISPENSING_ERROR',
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
