<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\Model\GoodsReceipt;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;
use App\Domains\Inventory\Domain\Quantity;
use App\Domains\Procurement\Contracts\ProcurementApi;
use InvalidArgumentException;

final class MatchGoodsReceiptInvoiceHandler
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly StockContextResolver $resolver,
        private readonly ProcurementApi $procurement,
    ) {}

    public function __invoke(MatchGoodsReceiptInvoice $command): void
    {
        $receipt = $this->receipts->getByIdForUpdate($command->goodsReceiptId);
        $this->resolver->assertActorScopedToFacility(
            (string) $receipt->facility_id,
            'procurement.invoices.match',
            $command->goodsReceiptId,
        );

        if ($receipt->source_type !== GoodsReceipt::SOURCE_PROCUREMENT || $receipt->po_ref === null) {
            throw new InvalidArgumentException('Only purchase-order receipts can be matched to a supplier invoice.');
        }
        if ($receipt->invoice_status !== GoodsReceipt::INVOICE_RECEIVED || $receipt->invoice_number === null) {
            throw new InvalidArgumentException('Attach the supplier invoice before matching it.');
        }

        $purchaseOrder = $this->procurement->goodsReceiptPurchaseOrder(
            (string) $receipt->facility_id,
            (string) $receipt->po_ref,
        );
        if ($purchaseOrder === null) {
            throw new InvalidArgumentException('The purchase order for this receipt could not be found.');
        }
        if (strtoupper($purchaseOrder->currency) !== strtoupper((string) $receipt->invoice_currency)) {
            throw new InvalidArgumentException('The invoice and purchase order currencies must match before invoice matching.');
        }
        $poLines = collect($purchaseOrder->lines)->keyBy('lineId');

        $receiptLines = GoodsReceiptLine::query()
            ->where('receipt_id', $receipt->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy(fn (GoodsReceiptLine $line): string => (string) $line->id);
        $matchLines = collect($command->lines)->keyBy('receipt_line_id');
        if ($matchLines->count() !== count($command->lines) || $matchLines->keys()->sort()->values()->all() !== $receiptLines->keys()->sort()->values()->all()) {
            throw new InvalidArgumentException('Provide invoice quantity and price for every goods-receipt line exactly once.');
        }

        $hasVariance = false;
        foreach ($receiptLines as $receiptLineId => $receiptLine) {
            $matchLine = $matchLines->get($receiptLineId);
            if (! is_array($matchLine)) {
                throw new InvalidArgumentException('Provide invoice quantity and price for every goods-receipt line exactly once.');
            }
            $poLine = $receiptLine->po_line_ref !== null
                ? $poLines->get((string) $receiptLine->po_line_ref)
                : null;
            if ($poLine === null) {
                throw new InvalidArgumentException('A goods-receipt line is missing its exact purchase-order line.');
            }

            $invoiceQuantity = Quantity::of($matchLine['invoice_quantity']);
            $invoiceUnitPrice = bcadd(Quantity::of($matchLine['invoice_unit_price']), '0', 2);
            $acceptedInEnteredUnit = bcdiv(
                (string) $receiptLine->quantity_accepted,
                (string) $receiptLine->pack_size,
                4,
            );
            $unitMatches = $receiptLine->po_unit !== null
                && strtoupper((string) $receiptLine->entered_unit) === strtoupper((string) $receiptLine->po_unit);
            if (bccomp($invoiceQuantity, $acceptedInEnteredUnit, 4) !== 0 || ! $unitMatches) {
                $hasVariance = true;
            }
            if ($unitMatches && $receiptLine->po_unit_price !== null
                && bccomp($invoiceUnitPrice, Quantity::of((string) $receiptLine->po_unit_price), 2) !== 0) {
                $hasVariance = true;
            } elseif ($receiptLine->po_unit_price === null) {
                $hasVariance = true;
            }

            $receiptLine->invoice_quantity = $invoiceQuantity;
            $receiptLine->invoice_unit_price = $invoiceUnitPrice;
            $receiptLine->save();
        }

        $receipt->markInvoiceMatched($command->actorId, $hasVariance);
        $this->receipts->save($receipt);
    }
}
