<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\CreatePurchaseOrder;
use App\Domains\Procurement\Presentation\Http\Request\StorePurchaseOrderRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class StorePurchaseOrderController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(StorePurchaseOrderRequest $request): RedirectResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'Select an active facility context.');

        $data = $request->validated();
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $lines = array_map(static fn (array $line): array => [
            'lineType' => 'STOCK',
            'itemRef' => (string) $line['item_id'],
            'quantity' => (string) $line['quantity'],
            'unit' => isset($line['unit']) && trim((string) $line['unit']) !== '' ? (string) $line['unit'] : null,
            'packSize' => isset($line['pack_size']) && trim((string) $line['pack_size']) !== '' ? (string) $line['pack_size'] : null,
            'unitPrice' => (string) $line['unit_price'],
        ], $data['lines']);

        $requisitionId = isset($data['requisition_id'])
            && is_string($data['requisition_id'])
            && $data['requisition_id'] !== ''
                ? $data['requisition_id']
                : null;

        try {
            $this->commands->dispatch(new CreatePurchaseOrder(
                purchaseOrderId: (string) Str::uuid7(),
                actorId: (string) $user->id,
                facilityId: $facilityId,
                supplierId: (string) $data['supplier_id'],
                lines: $lines,
                priority: (string) $data['priority'],
                notes: isset($data['notes']) && trim((string) $data['notes']) !== ''
                    ? (string) $data['notes']
                    : null,
                requisitionId: $requisitionId,
                costCentreRef: isset($data['cost_centre_ref']) && is_string($data['cost_centre_ref']) && $data['cost_centre_ref'] !== ''
                    ? $data['cost_centre_ref']
                    : null,
                budgetRef: isset($data['budget_ref']) && is_string($data['budget_ref']) && $data['budget_ref'] !== ''
                    ? $data['budget_ref']
                    : null,
                duplicateOverrideReason: isset($data['duplicate_override_reason'])
                    && is_string($data['duplicate_override_reason'])
                    && $data['duplicate_override_reason'] !== ''
                        ? $data['duplicate_override_reason']
                        : null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Purchase order created as a draft.');
    }
}
