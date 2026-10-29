<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Command;

use App\Domains\Procurement\Domain\Service\SupplierLifecycle;
use App\Domains\Procurement\Domain\SupplierRepository;

final class ChangeSupplierStatusHandler
{
    public function __construct(
        private readonly SupplierRepository $suppliers,
        private readonly SupplierLifecycle $lifecycle,
    ) {}

    public function __invoke(ChangeSupplierStatus $command): void
    {
        $supplier = $this->suppliers->getByIdForUpdate($command->supplierId);
        $target = strtoupper(trim($command->targetStatus));

        $this->lifecycle->assertCanTransition((string) $supplier->status, $target);

        $supplier->changeStatus($target, $command->reason);

        $this->suppliers->save($supplier);
    }
}
