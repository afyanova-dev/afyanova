<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application;

use App\Domains\Inventory\Contracts\InventoryApi;
use App\Domains\Procurement\Domain\Exception\ProcurementItemNotUsable;
use App\Domains\Procurement\Domain\Exception\ProcurementLineSetInvalid;
use App\Domains\Procurement\Domain\Model\PurchaseOrder;
use App\Domains\Procurement\Domain\Quantity;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Domain\NotFound;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Shared facility-scope refinement (ADR-133 / §42 / Invariant 42 — F9) and
 * inventory-item resolution (§52 / §170 / Invariant 16 — F7) for the
 * Procurement create handlers. Application-layer service — the `InventoryApi`
 * port + `ScopeChecker` only, no DB facade / query builder.
 *
 * @phpstan-type ResolvedLine array{lineType: string, itemRef: string|null, itemCode: string|null, itemName: string|null, description: string|null, quantity: string, orderedQuantity: string, uom: string, unit: string, packSize: string|null, requiredDate: string|null, unitPrice: string|null, deliveryLocationRef: string|null}
 */
final class ProcurementContextResolver
{
    public function __construct(
        private readonly InventoryApi $inventory,
        private readonly ScopeChecker $scope,
    ) {}

    /**
     * The actor must hold `$permissionKey` at `$facilityId`, or the create is
     * refused as `NotFound` (never an authz disclosure — the ADR-133 pattern).
     */
    public function assertActorScopedToFacility(
        string $facilityId,
        string $permissionKey,
        string $subjectId,
        ?string $departmentId = null,
    ): void {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new RuntimeException('Procurement command dispatched without an authenticated user (IC §5).');
        }

        if (! in_array($permissionKey, $this->scope->effectivePermissions($user, $facilityId, $departmentId), true)) {
            throw NotFound::aggregate(PurchaseOrder::class, $subjectId);
        }
    }

    /**
     * Validate + snapshot one requisition / PO line. STOCK lines must reference
     * a live inventory item; SERVICE lines must carry a description.
     *
     * A STOCK line may be written in the item's BASE unit (the default) or in
     * its declared buying unit — "3 BOX of 100" — in which case `quantity` is
     * what the buyer typed and `orderedQuantity` is the base equivalent
     * (`inventory-item-master-spec.md` §7). `unit_price` is always the price of
     * one ENTERED unit, so no price is ever divided or rounded.
     *
     * @param  array<string, mixed>  $line
     * @return array{lineType: string, itemRef: string|null, itemCode: string|null, itemName: string|null, description: string|null, quantity: string, orderedQuantity: string, uom: string, unit: string, packSize: string|null, requiredDate: string|null, unitPrice: string|null, deliveryLocationRef: string|null}
     */
    public function resolveLine(array $line, int $lineNo): array
    {
        $lineType = strtoupper(trim((string) ($line['lineType'] ?? 'STOCK')));
        $description = isset($line['description']) && trim((string) $line['description']) !== ''
            ? trim((string) $line['description'])
            : null;

        $itemRef = isset($line['itemRef']) && trim((string) $line['itemRef']) !== '' ? (string) $line['itemRef'] : null;
        $itemCode = null;
        $itemName = null;
        $uom = isset($line['uom']) && trim((string) $line['uom']) !== '' ? trim((string) $line['uom']) : null;
        $requestedUnit = isset($line['unit']) && trim((string) $line['unit']) !== ''
            ? strtoupper(trim((string) $line['unit']))
            : null;
        $requestedPackSize = isset($line['packSize']) && trim((string) $line['packSize']) !== ''
            ? Quantity::of((string) $line['packSize'])
            : null;

        if ($requestedPackSize !== null && ! Quantity::isPositive($requestedPackSize)) {
            throw ProcurementLineSetInvalid::packSizeNotPositive($lineNo);
        }

        $quantity = Quantity::of((string) ($line['quantity'] ?? '0'));
        $packSize = null;
        $unit = $requestedUnit;

        if ($lineType === 'STOCK') {
            if ($itemRef === null) {
                throw ProcurementLineSetInvalid::stockLineNeedsItemRef($lineNo);
            }

            $item = $this->inventory->getItem($itemRef);

            if ($item === null) {
                throw ProcurementItemNotUsable::unknown($itemRef);
            }

            if (! $item->active) {
                throw ProcurementItemNotUsable::retired($itemRef);
            }

            $itemCode = $item->code;
            $itemName = $item->name;
            // The base unit belongs to the item — never to the request.
            $uom = $item->uom;
            $baseUom = strtoupper($item->uom);
            $unit = $requestedUnit ?? $baseUom;
            $packUnit = $item->packUnit !== null ? strtoupper($item->packUnit) : null;

            if ($unit === $baseUom) {
                if ($requestedPackSize !== null) {
                    throw ProcurementLineSetInvalid::packSizeOnBaseUnit($lineNo);
                }
            } elseif ($packUnit !== null && $unit === $packUnit) {
                $packSize = $requestedPackSize
                    ?? ($item->packSize !== null ? Quantity::of($item->packSize) : null);

                if ($packSize === null) {
                    throw ProcurementLineSetInvalid::packSizeRequired($lineNo, $unit);
                }
            } else {
                throw ProcurementLineSetInvalid::unknownUnit($lineNo, $unit, $baseUom, $packUnit);
            }
        } elseif ($description === null) {
            throw ProcurementLineSetInvalid::serviceLineNeedsDescription($lineNo);
        } else {
            $unit = $unit ?? ($uom ?? 'EACH');

            if ($requestedPackSize !== null) {
                throw ProcurementLineSetInvalid::packSizeOnBaseUnit($lineNo);
            }
        }

        $orderedQuantity = $packSize === null ? $quantity : Quantity::multiply($quantity, $packSize);

        return [
            'lineType' => $lineType,
            'itemRef' => $lineType === 'STOCK' ? $itemRef : null,
            'itemCode' => $itemCode,
            'itemName' => $itemName,
            'description' => $description,
            'quantity' => $quantity,
            'orderedQuantity' => $orderedQuantity,
            'uom' => (string) ($uom ?? 'EACH'),
            'unit' => (string) $unit,
            'packSize' => $packSize,
            'requiredDate' => isset($line['requiredDate']) && trim((string) $line['requiredDate']) !== '' ? (string) $line['requiredDate'] : null,
            'unitPrice' => isset($line['unitPrice']) && trim((string) $line['unitPrice']) !== '' ? (string) $line['unitPrice'] : null,
            'deliveryLocationRef' => isset($line['deliveryLocationRef']) && trim((string) $line['deliveryLocationRef']) !== '' ? (string) $line['deliveryLocationRef'] : null,
        ];
    }
}
