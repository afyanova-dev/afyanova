<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\SetItemLevel;
use App\Domains\Inventory\Presentation\Http\Request\SetItemLevelRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class SetItemLevelController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetItemLevelRequest $request): RedirectResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403);

        try {
            $this->commands->dispatch(new SetItemLevel(
                itemId: (string) $request->validated('item_id'),
                facilityId: $facilityId,
                actorId: (string) $request->user()?->getKey(),
                minLevel: (string) $request->validated('min_level'),
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['item_level' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Minimum level saved.');
    }
}
