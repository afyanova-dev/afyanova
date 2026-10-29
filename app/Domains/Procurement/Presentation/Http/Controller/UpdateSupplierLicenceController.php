<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\UpdateSupplierLicence;
use App\Domains\Procurement\Presentation\Http\Request\UpdateSupplierLicenceRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;

final class UpdateSupplierLicenceController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(UpdateSupplierLicenceRequest $request, string $supplierId): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new UpdateSupplierLicence(
                supplierId: $supplierId,
                actorId: (string) $user->id,
                licenceNumber: $data['licence_number'] ?? null,
                licenceAuthority: $data['licence_authority'] ?? null,
                licenceExpiryDate: $data['licence_expiry_date'] ?? null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['supplier' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Supplier licence details updated.');
    }
}
