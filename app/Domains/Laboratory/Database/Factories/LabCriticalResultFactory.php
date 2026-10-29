<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabCriticalResult>
 */
class LabCriticalResultFactory extends Factory
{
    protected $model = LabCriticalResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'result_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'test_code' => 'K',
            'parameter_code' => 'K',
            'severity' => 'CRITICAL',
            'clinical_context' => null,
            'status' => LabCriticalResult::STATUS_INITIATED,
            'identified_by_user_id' => (string) Str::uuid7(),
            'identified_at' => now(),
            'acknowledged_by_user_id' => null,
            'acknowledged_at' => null,
            'acknowledgement_note' => null,
            'created_by_user_id' => (string) Str::uuid7(),
            'occurred_at' => now(),
            'recorded_at' => now(),
            'version' => 0,
        ];
    }

    public function forTenant(string $tenantId): static
    {
        return $this->state(['tenant_id' => $tenantId]);
    }

    public function forResult(string $resultId): static
    {
        return $this->state(['result_id' => $resultId]);
    }
}
