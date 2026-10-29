<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Database\Factories;

use App\Domains\Insurance\Domain\Model\Payer;
use App\Domains\Insurance\Domain\ValueObject\PayerStatus;
use App\Domains\Insurance\Domain\ValueObject\PayerType;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payer>
 */
class PayerFactory extends Factory
{
    protected $model = Payer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $now = CarbonImmutable::now();

        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'PAY-'.Str::upper(Str::random(6)),
            'name' => $this->faker->company().' Health',
            'payer_type' => PayerType::INSURANCE,
            'status' => PayerStatus::ACTIVE,
            'status_reason' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => $now,
            'recorded_at' => $now,
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function type(string $payerType): static
    {
        return $this->state(['payer_type' => $payerType]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => PayerStatus::INACTIVE]);
    }
}
