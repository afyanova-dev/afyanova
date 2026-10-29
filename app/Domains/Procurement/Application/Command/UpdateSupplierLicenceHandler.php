<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\SupplierRepository;

final class UpdateSupplierLicenceHandler
{
    public function __construct(private readonly SupplierRepository $suppliers) {}

    public function __invoke(UpdateSupplierLicence $command): void
    {
        $supplier = $this->suppliers->getByIdForUpdate($command->supplierId);
        $supplier->updateLicenceDetails(
            licenceNumber: $command->licenceNumber,
            licenceAuthority: $command->licenceAuthority,
            licenceExpiryDate: $command->licenceExpiryDate,
        );
        $this->suppliers->save($supplier);
    }
}
