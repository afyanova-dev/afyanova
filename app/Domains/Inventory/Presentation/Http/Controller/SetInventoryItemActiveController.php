<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\SetInventoryItemActive;
use App\Domains\Inventory\Presentation\Http\Request\SetInventoryItemActiveRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SetInventoryItemActiveController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(SetInventoryItemActiveRequest $request, string $itemId): RedirectResponse
    {
        abort_unless(Str::isUuid($itemId), 404);

        try {
            $this->commands->dispatch(new SetInventoryItemActive(
                itemId: $itemId,
                actorId: (string) $request->user()->id,
                active: (bool) $request->validated('active'),
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['item' => $exception->getMessage()]);
        }

        return back()->with('status', $request->boolean('active')
            ? 'Inventory item reactivated.'
            : 'Inventory item deactivated.');
    }
}
