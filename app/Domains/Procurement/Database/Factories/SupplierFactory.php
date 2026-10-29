<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'code' => 'SUP-'.Str::upper(Str::random(6)),
            'name' => 'Zana Medical Supplies Ltd',
            'registration_number' => null,
            'licence_number' => null,
            'licence_authority' => null,
            'licence_expiry_date' => null,
            'contact_name' => null,
            'contact_email' => null,
            'contact_phone' => null,
            'address' => null,
            'tax_identifier' => null,
            'payment_terms' => 'NET_30',
            'categories' => ['MEDICAL_CONSUMABLES'],
            'status' => Supplier::STATUS_ACTIVE,
            'status_reason' => null,
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

    public function suspended(): static
    {
        return $this->state(['status' => Supplier::STATUS_SUSPENDED, 'status_reason' => 'Compliance review']);
    }

    public function blacklisted(): static
    {
        return $this->state(['status' => Supplier::STATUS_BLACKLISTED, 'status_reason' => 'Fraud']);
    }
}
