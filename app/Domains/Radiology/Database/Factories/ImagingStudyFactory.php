<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImagingStudy>
 */
class ImagingStudyFactory extends Factory
{
    protected $model = ImagingStudy::class;

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
            'patient_id' => (string) Str::uuid7(),
            'encounter_id' => (string) Str::uuid7(),
            'accession_number' => 'RADAC-'.fake()->unique()->numerify('########'),
            'modality_code' => 'XR',
            'service_code' => 'XR-CHEST',
            'body_site' => 'CHEST',
            'laterality' => null,
            'status' => ImagingStudy::STATUS_SCHEDULED,
            'scheduled_for' => now()->addDay(),
            'appointment_ref' => null,
            'equipment_ref' => null,
            'verified_by_user_id' => null,
            'verified_at' => null,
            'performed_by_user_id' => null,
            'started_at' => null,
            'completed_at' => null,
            'outcome_status_code' => null,
            'outcome_detail' => null,
            'repeat_of_study_id' => null,
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

    public function forOrder(string $orderId, string $orderItemId): static
    {
        return $this->state(['order_id' => $orderId, 'order_item_id' => $orderItemId]);
    }

    public function withStatus(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
