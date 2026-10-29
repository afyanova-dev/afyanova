<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Domain\SupplierReader;

final class GetSupplierHandler
{
    public function __construct(private readonly SupplierReader $reader) {}

    public function __invoke(GetSupplier $query): SupplierSummary
    {
        return $this->reader->summary($query->supplierId);
    }
}
