<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Database\Factories;

use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LabSpecimen>
 */
class LabSpecimenFactory extends Factory
{
    protected $model = LabSpecimen::class;

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
            'patient_id' => (string) Str::uuid7(),
            'accession_number' => 'LABSP-'.fake()->unique()->numerify('########'),
            'specimen_type' => 'WHOLE_BLOOD',
            'container_type' => 'EDTA_TUBE',
            'collection_location' => 'WARD_3',
            'collected_by_user_id' => (string) Str::uuid7(),
            'collected_at' => now(),
            'received_by_user_id' => null,
            'received_at' => null,
            'status' => LabSpecimen::STATUS_COLLECTED,
            'rejection_reason_code' => null,
            'rejection_detail' => null,
            'recollection_requested_at' => null,
            'recollection_of_specimen_id' => null,
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
