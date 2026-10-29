<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Shared\Domain\NotFound;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * P0-03 — one page of open STOCK lines for the selected PO. A 500-line order
 * is narrowed with `q` (line number, item code or item name) instead of being
 * sent whole to the browser.
 */
final class SearchOpenPurchaseOrderLinesController
{
    public function __invoke(
        Request $request,
        string $purchaseOrderId,
        TenantContext $context,
        ProcurementApi $procurement,
    ): JsonResponse {
        Gate::authorize('inventory.receipts.create');

        abort_unless(Str::isUuid($purchaseOrderId), 404);

        $facilityId = $context->facilityId();
        abort_unless(
            $facilityId !== null && $facilityId !== '',
            403,
            'Select an active facility before receiving stock.',
        );

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            $page = $procurement->searchOpenPurchaseOrderLines(
                $facilityId,
                $purchaseOrderId,
                trim((string) ($data['q'] ?? '')),
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 100),
            );
        } catch (AuthorizationException) {
            abort(403, 'You are not authorised to receive against purchase orders.');
        } catch (NotFound) {
            abort(404, 'That purchase order is not open at this facility.');
        }

        return response()->json([
            'data' => array_map(fn ($line): array => [
                'lineId' => $line->lineId,
                'lineNo' => $line->lineNo,
                'itemRef' => $line->itemRef,
                'itemCode' => $line->itemCode,
                'itemName' => $line->itemName,
                'orderedQty' => $line->orderedQty,
                'receivedQty' => $line->receivedQty,
                'remainingQty' => $line->remainingQty,
                'uom' => $line->uom,
                'enteredUnit' => $line->enteredUnit,
                'enteredQty' => $line->enteredQty,
                'packSize' => $line->packSize,
            ], $page->lines),
            'meta' => [
                'currentPage' => $page->currentPage,
                'lastPage' => $page->lastPage,
                'perPage' => $page->perPage,
                'total' => $page->total,
            ],
        ]);
    }
}
