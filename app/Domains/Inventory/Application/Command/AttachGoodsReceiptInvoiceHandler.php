<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Application\Command;

use App\Domains\Inventory\Application\StockContextResolver;
use App\Domains\Inventory\Domain\GoodsReceiptRepository;
use App\Domains\Inventory\Domain\Model\GoodsReceiptLine;

final class AttachGoodsReceiptInvoiceHandler
{
    public function __construct(
        private readonly GoodsReceiptRepository $receipts,
        private readonly StockContextResolver $resolver,
    ) {}

    public function __invoke(AttachGoodsReceiptInvoice $command): void
    {
        $receipt = $this->receipts->getByIdForUpdate($command->goodsReceiptId);

        $this->resolver->assertActorScopedToFacility(
            (string) $receipt->facility_id,
            'inventory.receipts.create',
            $command->goodsReceiptId,
        );

        $receipt->attachInvoice(
            invoiceNumber: $command->invoiceNumber,
            invoiceDate: $command->invoiceDate,
            invoiceCurrency: $command->invoiceCurrency,
            invoiceTotal: $command->invoiceTotal,
        );

        GoodsReceiptLine::query()
            ->where('receipt_id', $receipt->id)
            ->update([
                'invoice_quantity' => null,
                'invoice_unit_price' => null,
            ]);

        $this->receipts->save($receipt);
    }
}
