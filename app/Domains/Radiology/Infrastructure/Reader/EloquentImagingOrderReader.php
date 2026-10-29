<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Infrastructure\Reader;

use App\Domains\Radiology\Contracts\Dto\ImagingOrderItemSummary;
use App\Domains\Radiology\Contracts\Dto\ImagingOrderSummary;
use App\Domains\Radiology\Domain\ImagingOrderReader;
use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Domains\Radiology\Domain\Model\ImagingOrderItem;
use App\Shared\Domain\NotFound;

final class EloquentImagingOrderReader implements ImagingOrderReader
{
    public function summary(string $orderId): ImagingOrderSummary
    {
        $order = ImagingOrder::query()->whereKey($orderId)->first()
            ?? throw NotFound::aggregate(ImagingOrder::class, $orderId);

        return $this->toSummary($order);
    }

    /**
     * @return list<ImagingOrderSummary>
     */
    public function activeForFacility(string $facilityId): array
    {
        $orders = ImagingOrder::query()
            ->where('facility_id', $facilityId)
            ->whereNotIn('status', [ImagingOrder::STATUS_REJECTED, ImagingOrder::STATUS_CANCELLED])
            ->orderByDesc('recorded_at')
            ->get();

        return array_values($orders->map(fn (ImagingOrder $order): ImagingOrderSummary => $this->toSummary($order))->all());
    }

    /**
     * @return list<ImagingOrderSummary>
     */
    public function forPatient(string $patientId): array
    {
        $orders = ImagingOrder::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->get();

        return array_values($orders->map(fn (ImagingOrder $order): ImagingOrderSummary => $this->toSummary($order))->all());
    }

    public function activeItemCount(string $orderId): int
    {
        return ImagingOrderItem::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', ImagingOrderItem::STATUS_CANCELLED)
            ->count();
    }

    private function toSummary(ImagingOrder $order): ImagingOrderSummary
    {
        $items = ImagingOrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('service_code')
            ->orderBy('id')
            ->get()
            ->map(fn (ImagingOrderItem $item): ImagingOrderItemSummary => new ImagingOrderItemSummary(
                itemId: (string) $item->id,
                imagingServiceId: (string) $item->imaging_service_id,
                serviceCode: (string) $item->service_code,
                serviceName: (string) $item->service_name,
                serviceDefinitionVersion: (int) $item->service_definition_version,
                modalityCode: (string) $item->modality_code,
                bodySite: $item->body_site,
                laterality: $item->laterality,
                status: (string) $item->status,
            ))
            ->all();

        return new ImagingOrderSummary(
            orderId: (string) $order->id,
            facilityId: (string) $order->facility_id,
            patientId: (string) $order->patient_id,
            encounterId: (string) $order->encounter_id,
            orderNumber: (string) $order->order_number,
            priority: (string) $order->priority,
            clinicalIndicationRef: $order->clinical_indication_ref,
            clinicalIndicationText: $order->clinical_indication_text,
            rejectionReason: $order->rejection_reason,
            cancellationReason: $order->cancellation_reason,
            status: (string) $order->status,
            items: array_values($items),
            createdAt: $order->created_at?->toISOString(),
            updatedAt: $order->updated_at?->toISOString(),
        );
    }
}
