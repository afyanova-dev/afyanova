<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabResult>
 */
class LabResultFactory extends Factory
{
    protected $model = LabResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
            'order_item_id' => (string) Str::uuid7(),
            'specimen_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'test_definition_id' => (string) Str::uuid7(),
            'test_code' => 'HB',
            'parameter_code' => 'HB',
            'parameter_name' => 'Haemoglobin',
            'value_type' => LabResult::VALUE_TYPE_NUMERIC,
            'value_numeric' => '12.400000',
            'value_code' => null,
            'value_text' => null,
            'unit' => 'g/dL',
            'reference_low' => '12.000000',
            'reference_high' => '16.000000',
            'reference_text' => null,
            'reference_range_source' => 'CATALOG',
            'abnormal_flag' => LabResult::FLAG_NORMAL,
            'result_source' => 'MANUAL',
            'method' => null,
            'status' => LabResult::STATUS_ENTERED,
            'release_hold_reason' => null,
            'entered_by_user_id' => (string) Str::uuid7(),
            'entered_at' => now(),
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'released_by_user_id' => null,
            'released_at' => null,
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

    public function forOrder(string $orderId): static
    {
        return $this->state(['order_id' => $orderId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
