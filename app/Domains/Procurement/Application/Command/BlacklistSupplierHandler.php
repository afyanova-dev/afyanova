<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Domains\Procurement\Domain\Service\SupplierLifecycle;
use App\Domains\Procurement\Domain\SupplierRepository;

final class BlacklistSupplierHandler
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly SupplierLifecycle $lifecycle,
    ) {}

    public function __invoke(BlacklistSupplier $command): void
    {
        $supplier = $this->suppliers->getByIdForUpdate($command->supplierId);

        $this->lifecycle->assertCanTransition((string) $supplier->status, Supplier::STATUS_BLACKLISTED);

        $supplier->changeStatus(Supplier::STATUS_BLACKLISTED, $command->reason);

        $this->suppliers->save($supplier);
    }
}
