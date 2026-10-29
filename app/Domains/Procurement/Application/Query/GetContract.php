<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Application\Query;

use App\Domains\Procurement\Domain\Model\Contract;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * One purchase contract + its lines (ADR-176 D-F). `view` gates on the coarse
 * `procurement.access` (a contract price is an agreed ONE-supplier price, NOT a
 * competing quote — the `PoLineSummary.unitPrice` tier). Contracts are
 * tenant-wide, so there is no ADR-133 facility refinement.
 */
#[ProtectedQuery(ability: 'view', subjectType: Contract::class)]
final readonly class GetContract
{
    public function __construct(public string $contractId)
    {
        if (! Str::isUuid($contractId)) {
            throw new InvalidArgumentException('GetContract::$contractId must be a UUID (IC §3).');
        }
    }
}
