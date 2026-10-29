<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Database\Factories;

use App\Domains\Procurement\Domain\Model\RfqInvitation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RfqInvitation>
 */
class RfqInvitationFactory extends Factory
{
    protected $model = RfqInvitation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'rfq_id' => (string) Str::uuid7(),
            'supplier_id' => (string) Str::uuid7(),
            'invited_at' => CarbonImmutable::now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }
}
