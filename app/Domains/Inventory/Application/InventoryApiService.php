<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application;

use App\Domains\Inventory\Application\Command\RecordConsumption;
use App\Domains\Inventory\Application\Command\ReserveStock;
use App\Domains\Inventory\Application\Command\ReverseConsumption;
use App\Domains\Inventory\Application\Query\GetAvailableStock;
use App\Domains\Inventory\Application\Query\GetStockLocation;
use App\Domains\Inventory\Contracts\Dto\AvailableStockSummary;
use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\Dto\ItemSummary;
use App\Domains\Inventory\Contracts\Dto\StockLocationRef;
use App\Domains\Inventory\Contracts\Dto\StockLocationSummary;
use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Inventory\Domain\ItemReader;
use App\Shared\Bus\CommandBus;
use App\Shared\Bus\QueryBus;
use App\Shared\Domain\NotFound;

/**
 * The `InventoryApi` implementation (ADR-171 D-N; ADR-184 D-G).
 *
 * `getItem` is an unprotected reference read (delegates straight to `ItemReader`).
 * `getStockLocation` / `getAvailableStock` cross the QueryBus via the existing
 * `#[ProtectedQuery]`s; `reserveStock` / `recordConsumption` cross the CommandBus
 * via the existing Inv.1 / Inv.2 commands — pure delegation, no new query /
 * command / reader / policy / migration / event (the `EncounterApiService`
 * port-write precedent). Every protected call runs under the caller's own
 * authorization.
 */
final class InventoryApiService implements InventoryApi
{
    public function __construct(
        private readonly ItemReader $items,
        private readonly QueryBus $queryBus,
        private readonly CommandBus $commandBus,
    ) {}

    public function getItem(string $itemId): ?InventoryItemRef
    {
        try {
            $item = $this->items->summary($itemId);
        } catch (NotFound) {
            return null;
        }

        return $this->toRef($item);
    }

    /**
     * @return list<InventoryItemRef>
     */
    public function getItems(?string $itemType = null, ?bool $active = null): array
    {
        return array_map(
            fn (ItemSummary $item): InventoryItemRef => $this->toRef($item),
            $this->items->list($itemType, $active),
        );
    }

    private function toRef(ItemSummary $item): InventoryItemRef
    {
        return new InventoryItemRef(
            itemId: $item->itemId,
            code: $item->code,
            name: $item->name,
            uom: $item->uom,
            packUnit: $item->packUnit,
            packSize: $item->packSize,
            active: $item->active,
            category: $item->category,
            itemType: $item->itemType,
        );
    }

    public function getStockLocation(string $locationId): ?StockLocationRef
    {
        try {
            /** @var StockLocationSummary $summary */
            $summary = $this->queryBus->dispatch(new GetStockLocation($locationId));
        } catch (NotFound) {
            return null;
        }

        return new StockLocationRef(
            locationId: $summary->locationId,
            facilityId: $summary->facilityId,
            code: $summary->code,
            name: $summary->name,
            active: $summary->active,
        );
    }

    public function getAvailableStock(string $itemId, string $facilityId): AvailableStockSummary
    {
        /** @var AvailableStockSummary $summary */
        $summary = $this->queryBus->dispatch(new GetAvailableStock($itemId, $facilityId));

        return $summary;
    }

    public function reserveStock(
        string $reservationId,
        string $actorId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $consumerType,
        ?string $consumerRef = null,
        ?string $batchNumber = null,
    ): void {
        $this->commandBus->dispatch(new ReserveStock(
            reservationId: $reservationId,
            actorId: $actorId,
            itemId: $itemId,
            locationId: $locationId,
            quantity: $quantity,
            consumerType: $consumerType,
            consumerRef: $consumerRef,
            batchNumber: $batchNumber,
        ));
    }

    public function recordConsumption(
        string $movementId,
        string $actorId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $sourceType,
        ?string $sourceRef = null,
        ?string $batchNumber = null,
        ?string $reservationId = null,
    ): void {
        $this->commandBus->dispatch(new RecordConsumption(
            movementId: $movementId,
            actorId: $actorId,
            itemId: $itemId,
            locationId: $locationId,
            quantity: $quantity,
            sourceType: $sourceType,
            sourceRef: $sourceRef,
            batchNumber: $batchNumber,
            reservationId: $reservationId,
        ));
    }

    public function reverseConsumption(
        string $reversalMovementId,
        string $actorId,
        string $originalMovementId,
        ?string $sourceRef = null,
        ?string $reason = null,
    ): void {
        $this->commandBus->dispatch(new ReverseConsumption(
            reversalMovementId: $reversalMovementId,
            actorId: $actorId,
            originalMovementId: $originalMovementId,
            sourceRef: $sourceRef,
            reason: $reason,
        ));
    }
}
