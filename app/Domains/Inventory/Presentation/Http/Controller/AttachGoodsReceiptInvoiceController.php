<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\AttachGoodsReceiptInvoice;
use App\Domains\Inventory\Presentation\Http\Request\AttachGoodsReceiptInvoiceRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class AttachGoodsReceiptInvoiceController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(
        AttachGoodsReceiptInvoiceRequest $request,
        string $goodsReceiptId,
    ): RedirectResponse {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $data = $request->validated();

        try {
            $this->commands->dispatch(new AttachGoodsReceiptInvoice(
                goodsReceiptId: $goodsReceiptId,
                actorId: (string) $user->id,
                invoiceNumber: (string) $data['invoice_number'],
                invoiceDate: isset($data['invoice_date']) ? (string) $data['invoice_date'] : null,
                invoiceCurrency: (string) $data['invoice_currency'],
                invoiceTotal: isset($data['invoice_total']) ? (string) $data['invoice_total'] : null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['invoice' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Supplier invoice attached to the goods receipt.');
    }
}
