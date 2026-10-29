<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\MatchGoodsReceiptInvoice;
use App\Domains\Inventory\Presentation\Http\Request\MatchGoodsReceiptInvoiceRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class MatchGoodsReceiptInvoiceController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(
        MatchGoodsReceiptInvoiceRequest $request,
        string $goodsReceiptId,
    ): RedirectResponse {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $data = $request->validated();
        try {
            $this->commands->dispatch(new MatchGoodsReceiptInvoice(
                goodsReceiptId: $goodsReceiptId,
                actorId: (string) $user->id,
                lines: $data['lines'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['invoice_match' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Supplier invoice matched to the goods receipt.');
    }
}
