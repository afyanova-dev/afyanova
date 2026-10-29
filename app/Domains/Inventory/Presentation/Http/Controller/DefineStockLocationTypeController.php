<?php

declare(strict_types=1);

namespace App\Domains\Inventory\Presentation\Http\Controller;

use App\Domains\Inventory\Application\Command\DefineStockLocationType;
use App\Domains\Inventory\Presentation\Http\Request\DefineStockLocationTypeRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DefineStockLocationTypeController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(DefineStockLocationTypeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $facilityId = $this->context->facilityId();
        abort_if(! $this->context->isGeneral() && ($facilityId === null || $facilityId === ''), 403, 'Select General or an active facility context.');
        $locationTypeFacilityId = $this->context->isGeneral() ? null : $facilityId;

        try {
            $this->commands->dispatch(new DefineStockLocationType(
                typeId: (string) Str::uuid7(),
                actorId: (string) $request->user()->id,
                locationTypeFacilityId: $locationTypeFacilityId,
                code: $data['code'],
                name: $data['name'],
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['code' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', $this->context->isGeneral()
            ? 'Location type saved for this tenant.'
            : 'Location type saved for this facility.');
    }
}
