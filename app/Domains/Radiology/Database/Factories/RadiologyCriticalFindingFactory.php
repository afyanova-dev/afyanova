<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RadiologyCriticalFinding>
 */
class RadiologyCriticalFindingFactory extends Factory
{
    protected $model = RadiologyCriticalFinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'report_id' => (string) Str::uuid7(),
            'study_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'accession_number' => 'RADAC-'.fake()->unique()->numerify('########'),
            'modality_code' => 'CT',
            'finding_summary' => 'Large tension pneumothorax on the right.',
            'severity' => 'CRITICAL',
            'status' => RadiologyCriticalFinding::STATUS_INITIATED,
            'identified_by_user_id' => (string) Str::uuid7(),
            'identified_at' => now(),
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

    public function forReport(string $reportId): static
    {
        return $this->state(['report_id' => $reportId]);
    }
}
