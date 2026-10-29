<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RadiologyReport>
 */
class RadiologyReportFactory extends Factory
{
    protected $model = RadiologyReport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => (string) Str::uuid7(),
            'tenant_id' => Tenant::factory(),
            'facility_id' => (string) Str::uuid7(),
            'study_id' => (string) Str::uuid7(),
            'order_id' => (string) Str::uuid7(),
            'order_item_id' => (string) Str::uuid7(),
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'accession_number' => 'RADAC-'.fake()->unique()->numerify('########'),
            'modality_code' => 'XR',
            'service_code' => 'XR-CHEST',
            'clinical_indication_text' => 'Suspected pneumonia.',
            'technique_text' => 'PA and lateral chest radiograph.',
            'findings_text' => 'Right lower-lobe opacity.',
            'impression_text' => 'Findings consistent with right lower-lobe pneumonia.',
            'recommendations_text' => null,
            'status' => RadiologyReport::STATUS_DRAFT,
            'amendment_count' => 0,
            'critical_finding_flagged' => false,
            'authored_by_user_id' => (string) Str::uuid7(),
            'authored_at' => now(),
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

    public function forStudy(string $studyId): static
    {
        return $this->state(['study_id' => $studyId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
