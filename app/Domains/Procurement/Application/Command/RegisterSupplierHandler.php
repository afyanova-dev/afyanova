<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Exception\SupplierAlreadyExists;
use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\SupplierRepository;
use App\Shared\Tenancy\TenantContext;

final class RegisterSupplierHandler
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly TenantContext $context,
    ) {}

    public function __invoke(RegisterSupplier $command): void
    {
        $tenantId = $this->context->tenantId();
        $code = strtoupper(trim($command->code));

        if ($this->suppliers->findActiveCode($tenantId, $code) !== null) {
            throw SupplierAlreadyExists::forCode($tenantId, $code);
        }

        $supplier = Supplier::register(
            id: $command->supplierId,
            tenantId: $tenantId,
            code: $command->code,
            name: $command->name,
            createdByUserId: $command->actorId,
            registrationNumber: $command->registrationNumber,
            contactName: $command->contactName,
            contactEmail: $command->contactEmail,
            contactPhone: $command->contactPhone,
            address: $command->address,
            taxIdentifier: $command->taxIdentifier,
            paymentTerms: $command->paymentTerms,
            categories: $command->categories,
            licenceNumber: $command->licenceNumber,
            licenceAuthority: $command->licenceAuthority,
            licenceExpiryDate: $command->licenceExpiryDate,
        );

        $this->suppliers->save($supplier);
    }
}
