<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Contracts\Dto\SupplierSummary;
use App\Domains\Procurement\Domain\SupplierReader;

final class GetSuppliersHandler
{
    public function __construct(private readonly SupplierReader $reader) {}

    /**
     * @return list<SupplierSummary>
     */
    public function __invoke(GetSuppliers $query): array
    {
        return $this->reader->list($query->status, $query->category);
    }
}
