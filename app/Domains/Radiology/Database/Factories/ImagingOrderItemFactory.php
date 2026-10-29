<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Database\Factories;

use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImagingOrderItem>
 */
class ImagingOrderItemFactory extends Factory
{
    protected $model = ImagingOrderItem::class;

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
            'imaging_service_id' => (string) Str::uuid7(),
            'service_code' => 'XR-CHEST',
            'service_name' => 'Chest X-Ray',
            'service_definition_version' => 1,
            'modality_code' => 'XR',
            'body_site' => 'CHEST',
            'laterality' => null,
            'status' => ImagingOrderItem::STATUS_ORDERED,
            'version' => 0,
        ];
    }

    public function forOrder(string $orderId): static
    {
        return $this->state(['order_id' => $orderId]);
    }

    public function forService(string $serviceId): static
    {
        return $this->state(['imaging_service_id' => $serviceId]);
    }
}
