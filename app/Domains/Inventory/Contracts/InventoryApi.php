<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Contracts;

use App\Domains\Inventory\Contracts\Dto\AvailableStockSummary;
use App\Domains\Inventory\Contracts\Dto\InventoryItemRef;
use App\Domains\Inventory\Contracts\Dto\StockLocationRef;

/**
 * The ONLY Inventory surface other modules may import (IC §2.1; ADR-170,
 * ADR-171 D-N). Grows per consumer — do not add a method without a concrete
 * caller (IC §26).
 *
 * ADR-170 published no method (no consumer in Inv.1). ADR-171 (Procurement
 * Proc.1a) adds `getItem` — an UNPROTECTED existence-plus-identity read.
 *
 * ADR-184 (Pharmacy Pharm.3) adds the first WRITES + the reservation reads —
 * the ADR-180 D-J deferrals. `getAvailableStock` / `getStockLocation` delegate
 * to the existing `#[ProtectedQuery]`s via the QueryBus; `reserveStock` /
 * `recordConsumption` delegate to the existing Inv.1 / Inv.2 commands via the
 * CommandBus (the `EncounterApi::recordEncounterRelationship` /
 * `BedWardApi::assignBed` port-write precedent). Every method runs under the
 * calling user's own authorization — the acting pharmacist must hold the
 * Inventory keys (`inventory.access` / `inventory.reservations.manage` /
 * `inventory.consumption.record`).
 */
interface InventoryApi
{
    /**
     * The narrow cross-domain reference for one item, or null if it does not
     * exist in the current tenant scope (RLS). Unprotected — no permission
     * check.
     */
    public function getItem(string $itemId): ?InventoryItemRef;

    /**
     * The tenant's item master as narrow cross-domain references, optionally
     * filtered by item type / active flag. Unprotected — item identity is not
     * stock data (the `getItem` precedent); RLS scopes it to the tenant. First
     * caller: the Billing catalog's medication → service mapping worklist
     * (frontend-foundation-inventory-operations.md Phase 5).
     *
     * @return list<InventoryItemRef>
     */
    public function getItems(?string $itemType = null, ?bool $active = null): array;

    /**
     * The narrow cross-domain reference for one stock location, or null if it
     * does not exist / is out of the caller's `inventory.access` scope (RLS +
     * the `GetStockLocation` facility check). ADR-184 D-G.
     */
    public function getStockLocation(string $locationId): ?StockLocationRef;

    /**
     * Available stock for one item across a facility's locations (ADR-170 D-M;
     * ADR-184 D-G). PROTECTED — delegates to the `GetAvailableStock`
     * `#[ProtectedQuery]`, so the caller must hold `inventory.access` at the
     * facility. Throws `App\Shared\Domain\NotFound` when out of scope.
     */
    public function getAvailableStock(string $itemId, string $facilityId): AvailableStockSummary;

    /**
     * Reserve `$quantity` of an item at a location for a consumer (ADR-180 D-E;
     * ADR-184 D-G). Delegates to the `ReserveStock` command — the caller must
     * hold `inventory.reservations.manage` at the location's facility. The
     * reservation id is client-minted (UUIDv7). `$consumerType` is open-coded
     * (Pharmacy passes `PHARMACY_ORDER`); `$consumerRef` is the opaque business
     * id (§150). Throws `App\Shared\Domain\NotFound` / `InsufficientStock` /
     * `AuthorizationException` exactly as the command does.
     */
    public function reserveStock(
        string $reservationId,
        string $actorId,
        string $itemId,
        string $locationId,
        string $quantity,
        string $consumerType,
        ?string $consumerRef = null,
        ?string $batchNumber = null,
    ): void;

    /**
     * Record a stock consumption, optionally fulfilling a reservation
     * (ADR-170 D-L; ADR-180 D-F; ADR-184 D-G). Delegates to the
     * `RecordConsumption` command — the caller must hold
     * `inventory.consumption.record` at the location's facility. `$movementId`
     * is client-minted. `$sourceType` is open-coded (Pharmacy passes
     * `PHARMACY`); `$sourceRef` is the opaque causing-activity id (§282). When
     * `$reservationId` is set the consumed quantity is released from
     * `reserved_qty` and the reservation flips FULFILLED.
     */
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
    ): void;

    /**
     * Reverse a prior consumption (ADR-185 D-C) — the "corrective inverse" of
     * {@see recordConsumption()}. Delegates to the `ReverseConsumption` command:
     * a compensating IN movement referencing `$originalMovementId`, NO approval,
     * NEVER `InsufficientStock`. The caller holds `inventory.consumption.record`
     * at the movement's facility. `$reversalMovementId` is client-minted;
     * `$sourceRef` is the opaque causing-activity id (a dispensing-reversal id).
     * Throws `StockMovementNotReversible` (not a CONSUMPTION / already reversed)
     * or `App\Shared\Domain\NotFound`. First caller: Pharmacy
     * `ReverseDispensingHandler`.
     */
    public function reverseConsumption(
        string $reversalMovementId,
        string $actorId,
        string $originalMovementId,
        ?string $sourceRef = null,
        ?string $reason = null,
    ): void;
}
