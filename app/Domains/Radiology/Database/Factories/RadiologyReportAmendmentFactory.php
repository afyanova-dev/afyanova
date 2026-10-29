<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Domains\Radiology\Domain\Model\RadiologyReportAmendment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RadiologyReportAmendment>
 */
class RadiologyReportAmendmentFactory extends Factory
{
    protected $model = RadiologyReportAmendment::class;

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
            'sequence' => 1,
            'amendment_type' => RadiologyReportAmendment::TYPE_CORRECTION,
            'previous_status' => RadiologyReport::STATUS_VERIFIED,
            'new_status' => RadiologyReport::STATUS_AMENDED,
            'previous_findings_text' => 'Right lower-lobe opacity.',
            'previous_impression_text' => 'Pneumonia.',
            'previous_recommendations_text' => null,
            'new_findings_text' => 'Right lower-lobe consolidation with air bronchograms.',
            'new_impression_text' => 'Lobar pneumonia.',
            'new_recommendations_text' => null,
            'reason' => 'Clarified the finding after review.',
            'amended_by_user_id' => (string) Str::uuid7(),
            'amended_at' => now(),
            'version' => 0,
        ];
    }

    public function forReport(string $reportId): static
    {
        return $this->state(['report_id' => $reportId]);
    }
}
