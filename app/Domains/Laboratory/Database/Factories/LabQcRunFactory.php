<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabQcRun>
 */
class LabQcRunFactory extends Factory
{
    protected $model = LabQcRun::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'analyzer_ref' => 'ANALYSER-A1',
            'test_definition_id' => (string) Str::uuid7(),
            'test_code' => 'GLU',
            'control_material' => 'BIO-RAD LEVEL 1',
            'control_level' => 'LEVEL_1',
            'expected_low' => '4.500000',
            'expected_high' => '5.500000',
            'observed_value' => '5.000000',
            'unit' => 'mmol/L',
            'status' => LabQcRun::STATUS_PASS,
            'reviewed_by_user_id' => null,
            'reviewed_at' => null,
            'review_note' => null,
            'performed_by_user_id' => (string) Str::uuid7(),
            'performed_at' => now(),
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
}
