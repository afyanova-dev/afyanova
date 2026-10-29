<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\DefineStockLocation;
use App\Domains\Inventory\Presentation\Http\Request\DefineStockLocationRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DefineStockLocationController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineStockLocationRequest $request): RedirectResponse
    {
        $facilityId = $this->context->facilityId();
        abort_if($facilityId === null || $facilityId === '', 403, 'No active facility context.');
        $data = $request->validated();

        try {
            $this->commands->dispatch(new DefineStockLocation(
                locationId: (string) Str::uuid7(),
                actorId: (string) $request->user()->id,
                facilityId: $facilityId,
                code: $data['code'],
                name: $data['name'],
                locationType: $data['location_type'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['location' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Stock location added to this facility.');
    }
}
