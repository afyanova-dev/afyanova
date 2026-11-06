<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application;

use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Inventory\Domain\Exception\InsufficientStock;
use App\Domains\Inventory\Domain\Exception\InventoryItemNotStockable;
use App\Domains\Inventory\Domain\Exception\StockBatchExpired;
use App\Domains\Laboratory\Application\Command\FinalizeLabExecutionMaterialUse;
use App\Domains\Laboratory\Application\Command\RecordLabExecutionMaterialUse;
use App\Domains\Laboratory\Domain\Model\LabExecutionMaterialUse;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\NotFound;
use Illuminate\Validation\ValidationException;

final class LaboratoryExecutionWorkflow
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly InventoryApi $inventory,
    ) {}

    public function recordMaterialUse(RecordLabExecutionMaterialUse $command): void
    {
        /** @var LabExecutionMaterialUse $use */
        $use = $this->commands->dispatch($command);

        if ($use->status === LabExecutionMaterialUse::STATUS_FAILED) {
            throw ValidationException::withMessages([
                'material' => $use->failure_message ?? 'This material-use attempt failed. Start a new attempt with corrected details.',
            ]);
        }

        try {
            $this->inventory->recordConsumption(
                movementId: (string) $use->movement_id,
                actorId: (string) $command->actorId,
                itemId: (string) $use->inventory_item_id,
                locationId: (string) $use->location_id,
                quantity: (string) $use->quantity,
                sourceType: 'LABORATORY',
                sourceRef: (string) $use->execution_id,
                batchNumber: $use->batch_number,
                idempotencyKey: 'laboratory-material-use:'.(string) $use->id,
            );
        } catch (InsufficientStock|InventoryItemNotStockable|StockBatchExpired|NotFound $exception) {
            $failureMessage = match (true) {
                $exception instanceof InsufficientStock => 'Insufficient available stock. Select a different location or batch, or replenish stock.',
                $exception instanceof StockBatchExpired => 'The selected inventory batch is expired. Choose an unexpired batch.',
                $exception instanceof InventoryItemNotStockable => 'Inventory rejected this item or its tracking details. Check the item status and batch or serial requirements.',
                default => 'The selected location, item, or batch is not available in this facility.',
            };

            $this->commands->dispatch(new FinalizeLabExecutionMaterialUse(
                actorId: $command->actorId,
                executionId: $command->executionId,
                useId: (string) $use->id,
                outcome: FinalizeLabExecutionMaterialUse::OUTCOME_FAILED,
                failureMessage: $failureMessage,
            ));

            throw ValidationException::withMessages(['material' => $failureMessage]);
        }

        $this->commands->dispatch(new FinalizeLabExecutionMaterialUse(
            actorId: $command->actorId,
            executionId: $command->executionId,
            useId: (string) $use->id,
            outcome: FinalizeLabExecutionMaterialUse::OUTCOME_CONSUMED,
        ));
    }
}
