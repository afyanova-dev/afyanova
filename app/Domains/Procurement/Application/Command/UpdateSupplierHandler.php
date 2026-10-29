<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\SupplierRepository;

final class UpdateSupplierHandler
{
    public function __construct(private readonly SupplierRepository $suppliers) {}

    public function __invoke(UpdateSupplier $command): void
    {
        $supplier = $this->suppliers->getByIdForUpdate($command->supplierId);

        $supplier->updateDetails(
            name: $command->name,
            registrationNumber: $command->registrationNumber,
            contactName: $command->contactName,
            contactEmail: $command->contactEmail,
            contactPhone: $command->contactPhone,
            address: $command->address,
            taxIdentifier: $command->taxIdentifier,
            paymentTerms: $command->paymentTerms,
            categories: $command->categories,
        );

        $this->suppliers->save($supplier);
    }
}
