<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Contracts\ProcurementApi;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * P2-02 — the buying form's price-history lookup. The query behind the API is
 * `#[ProtectedQuery]`-gated and facility/department refined.
 */
final class ItemPriceReferencesController
{
    public function __construct(
        private readonly ProcurementApi $procurement,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'item_id' => ['required', 'uuid'],
        ]);

        return response()->json([
            'data' => $this->procurement->getItemPriceReferences(
                (string) $data['supplier_id'],
                (string) $data['item_id'],
            ),
        ]);
    }
}
