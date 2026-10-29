<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\SetStockLocationActive;
use App\Domains\Inventory\Presentation\Http\Request\SetStockLocationActiveRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class SetStockLocationActiveController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(SetStockLocationActiveRequest $request, string $locationId): RedirectResponse
    {
        abort_unless(Str::isUuid($locationId), 404);
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');

        try {
            $this->commands->dispatch(new SetStockLocationActive(
                locationId: $locationId,
                actorId: (string) $request->user()->id,
                facilityId: $facilityId,
                active: (bool) $request->validated('active'),
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()]);
        }

        return back()->with('status', $request->boolean('active')
            ? 'Stock location reactivated.'
            : 'Stock location deactivated.');
    }
}
