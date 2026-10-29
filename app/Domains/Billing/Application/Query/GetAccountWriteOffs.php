<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\WriteOff;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The write-offs on one Financial Account, newest first
 * (20-billing-and-payments-domain.md §78; ADR-191 D7-A). `view` gates on the
 * coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: WriteOff::class)]
final readonly class GetAccountWriteOffs
{
    public function __construct(public string $financialAccountId)
    {
        if (! Str::isUuid($financialAccountId)) {
            throw new InvalidArgumentException('GetAccountWriteOffs::$financialAccountId must be a UUID (IC §3).');
        }
    }
}
