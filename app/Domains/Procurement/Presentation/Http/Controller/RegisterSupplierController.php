<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Procurement\Application\Command\RegisterSupplier;
use App\Domains\Procurement\Presentation\Http\Request\RegisterSupplierRequest;
use App\Shared\Bus\CommandBus;
use App\Shared\Domain\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class RegisterSupplierController
{
    public function __construct(private readonly CommandBus $commands) {}

    public function __invoke(RegisterSupplierRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if ($user === null) {
            abort(401);
        }

        try {
            $this->commands->dispatch(new RegisterSupplier(
                supplierId: (string) Str::uuid7(),
                actorId: (string) $user->id,
                code: $data['code'],
                name: $data['name'],
                registrationNumber: $data['registration_number'] ?? null,
                contactName: $data['contact_name'] ?? null,
                contactEmail: $data['contact_email'] ?? null,
                contactPhone: $data['contact_phone'] ?? null,
                address: $data['address'] ?? null,
                taxIdentifier: $data['tax_identifier'] ?? null,
                paymentTerms: $data['payment_terms'] ?? null,
                categories: $data['categories'] ?? [],
                licenceNumber: $data['licence_number'] ?? null,
                licenceAuthority: $data['licence_authority'] ?? null,
                licenceExpiryDate: $data['licence_expiry_date'] ?? null,
            ));
        } catch (DomainException|InvalidArgumentException $exception) {
            return back()->withErrors(['supplier' => $exception->getMessage()])->withInput();
        }

        return back()->with('status', 'Supplier registered.');
    }
}
