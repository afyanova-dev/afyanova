<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\ReturnGoodsToSupplier;
use App\Domains\Inventory\Presentation\Http\Request\ReturnGoodsToSupplierRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class ReturnGoodsToSupplierController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(
        ReturnGoodsToSupplierRequest $request,
        string $goodsReceiptId,
    ): RedirectResponse {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        $data = $request->validated();
        try {
            $returnNumber = $this->commands->dispatch(new ReturnGoodsToSupplier(
                returnId: $data['return_id'],
                actorId: (string) $user->id,
                receiptId: $goodsReceiptId,
                locationId: $data['location_id'],
                reason: $data['reason'],
                lines: $data['lines'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['supplier_return' => $exception->getMessage()])->withInput();
        }

        if (! is_string($returnNumber)) {
            throw new \RuntimeException('Supplier return was posted but its document number was not returned.');
        }

        return back()->with('status', "Supplier return {$returnNumber} posted; stock history was preserved.");
    }
}
