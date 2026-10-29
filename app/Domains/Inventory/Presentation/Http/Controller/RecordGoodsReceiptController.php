<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\RecordGoodsReceipt;
use App\Domains\Inventory\Application\Command\RecordGoodsReceiptLine;
use App\Domains\Inventory\Presentation\Http\Request\RecordGoodsReceiptRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

/**
 * Posts the Phase 1 receiving workbench as ONE goods receipt.
 *
 * The page uses an Inertia post with `preserveState`, so the receiving panel
 * survives the redirect; the partial reload refreshes open PO lines without a
 * full page reload (P0-04).
 */
final class RecordGoodsReceiptController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(RecordGoodsReceiptRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        $data = $request->validated();

        $lines = [];
        foreach ($data['lines'] as $line) {
            $lines[] = new RecordGoodsReceiptLine(
                receiptLineId: $line['receipt_line_id'],
                itemId: $line['item_id'],
                poLineRef: $line['po_line_id'] ?? null,
                enteredUnit: $line['entered_unit'],
                enteredQuantity: (string) $line['entered_quantity'],
                packSize: (string) $line['pack_size'],
                quantityDelivered: (string) $line['quantity_delivered'],
                quantityRejected: (string) ($line['quantity_rejected'] ?? '0'),
                batchNumber: $line['batch_number'] ?? null,
                expiryDate: $line['expiry_date'] ?? null,
                manufactureDate: $line['manufacture_date'] ?? null,
                condition: $line['condition'],
                conditionNotes: $line['condition_notes'] ?? null,
                rejectionReason: $line['rejection_reason'] ?? null,
                substitutedForItemId: $line['substituted_for_item_id'] ?? null,
            );
        }

        try {
            $this->commands->dispatch(new RecordGoodsReceipt(
                receiptId: $data['receipt_id'],
                actorId: (string) $user->id,
                locationId: $data['location_id'],
                sourceType: $data['source_type'],
                lines: $lines,
                poRef: $data['po_ref'] ?? null,
                supplierRef: $data['supplier_ref'] ?? null,
                deliveryNoteNumber: $data['delivery_note_number'] ?? null,
                deliveryNoteMissingReason: $data['delivery_note_missing_reason'] ?? null,
                invoiceNumber: $data['invoice_number'] ?? null,
                invoiceDate: $data['invoice_date'] ?? null,
                invoiceStatus: $data['invoice_status'],
                invoiceCurrency: $data['invoice_currency'],
                invoiceTotal: isset($data['invoice_total']) ? (string) $data['invoice_total'] : null,
                receivedAt: $data['received_at'] ?? null,
                remarks: $data['remarks'] ?? null,
                reason: $data['reason'] ?? null,
                idempotencyKey: $data['idempotency_key'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['receipt' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('inventory.index', [
            'receipt_id' => $data['receipt_id'],
        ])->with('status', 'Goods receipt posted.');
    }
}
