<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\ClosePurchaseOrderShort;
use App\Domains\Procurement\Presentation\Http\Request\ClosePurchaseOrderShortRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class ClosePurchaseOrderShortController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(ClosePurchaseOrderShortRequest $request, string $purchaseOrderId): RedirectResponse
    {
        abort_unless(Str::isUuid($purchaseOrderId), 404);

        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new ClosePurchaseOrderShort(
                $purchaseOrderId,
                (string) $user->id,
                (string) $request->validated()['reason'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['order' => $exception->getMessage()]);
        }

        return back()->with('status', 'Purchase order closed short.');
    }
}
