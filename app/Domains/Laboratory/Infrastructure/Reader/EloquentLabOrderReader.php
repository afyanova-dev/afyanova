<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Laboratory\Contracts\Dto\LabOrderItemResultContext;
use App\Domains\Laboratory\Contracts\Dto\LabOrderItemSummary;
use App\Domains\Laboratory\Contracts\Dto\LabOrderSummary;
use App\Domains\Laboratory\Domain\LabOrderReader;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Shared\Domain\NotFound;

final class EloquentLabOrderReader implements LabOrderReader
{
    public function summary(string $orderId): LabOrderSummary
    {
        $order = LabOrder::query()->whereKey($orderId)->first()
            ?? throw NotFound::aggregate(LabOrder::class, $orderId);

        return $this->toSummary($order);
    }

    public function itemResultContext(string $orderItemId): LabOrderItemResultContext
    {
        $item = LabOrderItem::query()->whereKey($orderItemId)->first()
            ?? throw NotFound::aggregate(LabOrderItem::class, $orderItemId);

        $order = LabOrder::query()->whereKey($item->order_id)->first()
            ?? throw NotFound::aggregate(LabOrder::class, (string) $item->order_id);

        return new LabOrderItemResultContext(
            orderItemId: (string) $item->id,
            orderId: (string) $item->order_id,
            patientId: (string) $order->patient_id,
            facilityId: (string) $order->facility_id,
            testDefinitionId: (string) $item->test_definition_id,
            testCode: (string) $item->test_code,
            testName: (string) $item->test_name,
        );
    }

    /**
     * @return list<LabOrderSummary>
     */
    public function activeForFacility(string $facilityId): array
    {
        $orders = LabOrder::query()
            ->where('facility_id', $facilityId)
            ->whereNotIn('status', [LabOrder::STATUS_REJECTED, LabOrder::STATUS_CANCELLED])
            ->orderByDesc('recorded_at')
            ->get();

        return array_values($orders->map(fn (LabOrder $order): LabOrderSummary => $this->toSummary($order))->all());
    }

    /**
     * @return list<LabOrderSummary>
     */
    public function forPatient(string $patientId): array
    {
        $orders = LabOrder::query()
            ->where('patient_id', $patientId)
            ->orderByDesc('recorded_at')
            ->get();

        return array_values($orders->map(fn (LabOrder $order): LabOrderSummary => $this->toSummary($order))->all());
    }

    public function activeItemCount(string $orderId): int
    {
        return LabOrderItem::query()
            ->where('order_id', $orderId)
            ->where('status', '!=', LabOrderItem::STATUS_CANCELLED)
            ->count();
    }

    private function toSummary(LabOrder $order): LabOrderSummary
    {
        $items = LabOrderItem::query()
            ->where('order_id', $order->id)
            ->orderBy('test_code')
            ->orderBy('id')
            ->get()
            ->map(fn (LabOrderItem $item): LabOrderItemSummary => new LabOrderItemSummary(
                itemId: (string) $item->id,
                testDefinitionId: (string) $item->test_definition_id,
                testCode: (string) $item->test_code,
                testName: (string) $item->test_name,
                testDefinitionVersion: (int) $item->test_definition_version,
                status: (string) $item->status,
                orderedViaPanelId: $item->ordered_via_panel_id,
            ))
            ->all();

        return new LabOrderSummary(
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
