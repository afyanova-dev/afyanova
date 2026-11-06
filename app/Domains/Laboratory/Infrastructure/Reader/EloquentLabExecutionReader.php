<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Infrastructure\Reader;

use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionMaterialRequirementSummary;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionMaterialUseSummary;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionQueueItemSummary;
use App\Domains\Laboratory\Contracts\Dto\LabExecutionSummary;
use App\Domains\Laboratory\Domain\LabExecutionReader;
use App\Domains\Laboratory\Domain\Model\LabExecution;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialRequirement;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Domains\Laboratory\Domain\Model\LabOrderItem;
use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Domain\NotFound;

final class EloquentLabExecutionReader implements LabExecutionReader
{
    public function queueForFacility(string $facilityId): array
    {
        $orders = LabOrder::query()
            ->where('facility_id', $facilityId)
            ->where('status', LabOrder::STATUS_PROCESSING)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->get(['id', 'order_number', 'priority']);

        if ($orders->isEmpty()) {
            return [];
        }

        $items = LabOrderItem::query()
            ->whereIn('order_id', $orders->modelKeys())
            ->where('status', LabOrderItem::STATUS_ORDERED)
            ->orderBy('test_code')
            ->orderBy('id')
            ->get(['id', 'order_id', 'test_definition_id', 'test_code', 'test_name']);

        if ($items->isEmpty()) {
            return [];
        }

        $executions = LabExecution::query()
            ->whereIn('order_item_id', $items->modelKeys())
            ->whereIn('status', [LabExecution::STATUS_IN_PROGRESS, LabExecution::STATUS_COMPLETED])
            ->get(['id', 'order_item_id', 'status']);
        $activeExecutions = $executions
            ->where('status', LabExecution::STATUS_IN_PROGRESS)
            ->keyBy('order_item_id');
        $completedItemIds = $executions
            ->where('status', LabExecution::STATUS_COMPLETED)
            ->pluck('order_item_id')
            ->map(static fn (string $id): string => $id)
            ->all();

        $acceptedSpecimens = LabSpecimen::query()
            ->whereIn('order_id', $orders->modelKeys())
            ->where('status', LabSpecimen::STATUS_ACCEPTED)
            ->orderBy('accession_number')
            ->get(['id', 'order_id', 'accession_number', 'specimen_type', 'container_type'])
            ->groupBy('order_id');
        $ordersById = $orders->keyBy('id');

        return array_values($items
            ->reject(static fn (LabOrderItem $item): bool => in_array((string) $item->id, $completedItemIds, true))
            ->map(function (LabOrderItem $item) use ($ordersById, $activeExecutions, $acceptedSpecimens): LabExecutionQueueItemSummary {
                /** @var LabOrder $order */
                $order = $ordersById->get((string) $item->order_id);
                $specimens = $acceptedSpecimens->get((string) $order->id, collect())
                    ->map(static fn (LabSpecimen $specimen): array => [
                        'specimenId' => (string) $specimen->id,
                        'accessionNumber' => (string) $specimen->accession_number,
                        'specimenType' => (string) $specimen->specimen_type,
                        'containerType' => $specimen->container_type === null ? null : (string) $specimen->container_type,
                    ])
                    ->all();

                return new LabExecutionQueueItemSummary(
                    orderId: (string) $order->id,
                    orderNumber: (string) $order->order_number,
                    priority: (string) $order->priority,
                    orderItemId: (string) $item->id,
                    testDefinitionId: (string) $item->test_definition_id,
                    testCode: (string) $item->test_code,
                    testName: (string) $item->test_name,
                    acceptedSpecimens: array_values($specimens),
                    executionId: $activeExecutions->get((string) $item->id)?->id,
                );
            })->all());
    }

    public function summary(string $executionId): LabExecutionSummary
    {
        $execution = LabExecution::query()->whereKey($executionId)->first()
            ?? throw NotFound::aggregate(LabExecution::class, $executionId);

        $order = LabOrder::query()->whereKey($execution->order_id)->first()
            ?? throw NotFound::aggregate(LabOrder::class, (string) $execution->order_id);

        $requirements = LabExecutionMaterialRequirement::query()
            ->where('execution_id', $execution->id)
            ->orderBy('item_code_snapshot')
            ->orderBy('id')
            ->get();
        $usesByRequirement = LabExecutionMaterialUse::query()
            ->where('execution_id', $execution->id)
            ->orderBy('attempt_number')
            ->get()
            ->groupBy('requirement_id');

        $materials = $requirements->map(function (LabExecutionMaterialRequirement $requirement) use ($usesByRequirement): LabExecutionMaterialRequirementSummary {
            /** @var iterable<LabExecutionMaterialUse> $uses */
            $uses = $usesByRequirement->get((string) $requirement->id, collect());
            $consumedQuantity = '0.0000';
            $useSummaries = [];

            foreach ($uses as $use) {
                if ($use->status === LabExecutionMaterialUse::STATUS_CONSUMED) {
                    $consumedQuantity = Quantity::add($consumedQuantity, (string) $use->quantity);
                }

                $useSummaries[] = new LabExecutionMaterialUseSummary(
                    useId: (string) $use->id,
                    movementId: (string) $use->movement_id,
                    locationId: (string) $use->location_id,
                    batchNumber: $use->batch_number,
                    quantity: (string) $use->quantity,
                    status: (string) $use->status,
                    failureMessage: $use->failure_message,
                    consumedAt: $use->consumed_at?->toISOString(),
                );
            }

            $remainingQuantity = Quantity::sub((string) $requirement->quantity, $consumedQuantity);
            if (Quantity::compare($remainingQuantity, '0') < 0) {
                $remainingQuantity = '0.0000';
            }

            return new LabExecutionMaterialRequirementSummary(
                requirementId: (string) $requirement->id,
                inventoryItemId: (string) $requirement->inventory_item_id,
                itemCode: (string) $requirement->item_code_snapshot,
                itemName: (string) $requirement->item_name_snapshot,
                itemUom: (string) $requirement->item_uom_snapshot,
                role: (string) $requirement->requirement_role,
                required: (bool) $requirement->required,
                usageScope: (string) $requirement->usage_scope,
                requiredQuantity: (string) $requirement->quantity,
                consumedQuantity: $consumedQuantity,
                remainingQuantity: $remainingQuantity,
                uses: $useSummaries,
            );
        })->all();

        return new LabExecutionSummary(
            executionId: (string) $execution->id,
            orderId: (string) $execution->order_id,
            orderItemId: (string) $execution->order_item_id,
            specimenId: (string) $execution->specimen_id,
            orderNumber: (string) $order->order_number,
            testCode: (string) $execution->test_code_snapshot,
            testName: (string) $execution->test_name_snapshot,
            attemptNumber: (int) $execution->attempt_number,
            methodId: (string) $execution->method_id,
            methodCode: (string) $execution->method_code_snapshot,
            methodName: (string) $execution->method_name_snapshot,
            methodVersion: (int) $execution->method_version,
            instrumentId: $execution->instrument_id,
            instrumentIdentifier: $execution->instrument_identifier_snapshot,
            status: (string) $execution->status,
            startedAt: $execution->started_at->toISOString(),
            completedAt: $execution->completed_at?->toISOString(),
            abortedAt: $execution->aborted_at?->toISOString(),
            abortReason: $execution->abort_reason,
            materials: array_values($materials),
        );
    }
}
