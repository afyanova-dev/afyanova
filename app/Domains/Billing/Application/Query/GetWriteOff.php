<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\WriteOff;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one write-off (20-billing-and-payments-domain.md §78; ADR-191 D7-A).
 * `view` gates on the coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: WriteOff::class)]
final readonly class GetWriteOff
{
    public function __construct(public string $writeOffId)
    {
        if (! Str::isUuid($writeOffId)) {
            throw new InvalidArgumentException('GetWriteOff::$writeOffId must be a UUID (IC §3).');
        }
    }
}
