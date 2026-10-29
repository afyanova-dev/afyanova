<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Domains\Laboratory\Domain\Model\LabResultAmendment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabResultAmendment>
 */
class LabResultAmendmentFactory extends Factory
{
    protected $model = LabResultAmendment::class;

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
            'sequence' => 1,
            'amendment_type' => LabResultAmendment::TYPE_CORRECTION,
            'previous_status' => LabResult::STATUS_RELEASED,
            'new_status' => LabResult::STATUS_AMENDED,
            'previous_value_type' => LabResult::VALUE_TYPE_NUMERIC,
            'previous_value_numeric' => '8.100000',
            'previous_value_code' => null,
            'previous_value_text' => null,
            'new_value_type' => LabResult::VALUE_TYPE_NUMERIC,
            'new_value_numeric' => '12.400000',
            'new_value_code' => null,
            'new_value_text' => null,
            'reason' => 'Transcription error corrected against the analyzer printout.',
            'replacement_result_id' => null,
            'amended_by_user_id' => (string) Str::uuid7(),
            'amended_at' => now(),
            'version' => 0,
        ];
    }

    public function forResult(string $resultId): static
    {
        return $this->state(['result_id' => $resultId]);
    }
}
