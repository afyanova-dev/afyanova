<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Supplier;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Read one supplier master record (ADR-171 D-J). Coarse `procurement.access`. */
#[ProtectedQuery(ability: 'view', subjectType: Supplier::class)]
final readonly class GetSupplier
{
    public function __construct(public string $supplierId)
    {
        if (! Str::isUuid($supplierId)) {
            throw new InvalidArgumentException('GetSupplier::$supplierId must be a UUID (IC §3).');
        }
    }
}
