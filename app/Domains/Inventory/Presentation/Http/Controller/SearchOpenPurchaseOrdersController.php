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

/**
 * P0-03 — server-side search for the receiving workbench's PO picker.
 *
 * This is a JSON endpoint, not an Inertia page: the picker must not force a
 * full page visit, and the Inventory page must not carry every open PO (and
 * every line) in its props.
 */
final class SearchOpenPurchaseOrdersController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        ProcurementApi $procurement,
    ): JsonResponse {
        Gate::authorize('inventory.receipts.create');

        $facilityId = $context->facilityId();
        abort_unless(
            $facilityId !== null && $facilityId !== '',
            403,
            'Select an active facility before receiving stock.',
        );

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $page = $procurement->searchOpenPurchaseOrders(
                $facilityId,
                trim((string) ($data['q'] ?? '')),
                (int) ($data['page'] ?? 1),
                (int) ($data['per_page'] ?? 20),
            );
        } catch (AuthorizationException) {
            abort(403, 'You are not authorised to receive against purchase orders.');
        } catch (NotFound) {
            return response()->json([
                'data' => [],
                'meta' => [
                    'currentPage' => 1,
                    'lastPage' => 1,
                    'perPage' => (int) ($data['per_page'] ?? 20),
                    'total' => 0,
                ],
            ]);
        }

        return response()->json([
            'data' => array_map(fn ($po): array => [
                'purchaseOrderId' => $po->purchaseOrderId,
                'poNumber' => $po->poNumber,
                'status' => $po->status,
                'lines' => [],
                'openLineCount' => $po->openLineCount,
                'remainingLineCount' => $po->remainingLineCount,
            ], $page->orders),
            'meta' => [
                'currentPage' => $page->currentPage,
                'lastPage' => $page->lastPage,
                'perPage' => $page->perPage,
                'total' => $page->total,
            ],
        ]);
    }
}
