<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Contract;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Purchase contracts for the tenant, optionally filtered by supplier / status
 * (ADR-176 D-F). `view` gates on `procurement.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Contract::class)]
final readonly class SearchContracts
{
    public function __construct(
        public ?string $supplierId = null,
        public ?string $status = null,
    ) {
        if ($supplierId !== null && ! Str::isUuid($supplierId)) {
            throw new InvalidArgumentException('SearchContracts::$supplierId must be a UUID or null (IC §3).');
        }
    }
}
