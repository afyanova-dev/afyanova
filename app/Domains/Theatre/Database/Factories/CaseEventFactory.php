<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Database\Factories;

use App\Domains\Theatre\Domain\Model\CaseEvent;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CaseEvent>
 */
class CaseEventFactory extends Factory
{
    protected $model = CaseEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'case_id' => (string) Str::uuid7(),
            'event_type' => 'INCISION',
            'event_at' => now(),
            'note' => null,
            'recorded_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function atFacility(string $facilityId): static
    {
        return $this->state(['facility_id' => $facilityId]);
    }

    public function forCase(string $caseId): static
    {
        return $this->state(['case_id' => $caseId]);
    }
}
