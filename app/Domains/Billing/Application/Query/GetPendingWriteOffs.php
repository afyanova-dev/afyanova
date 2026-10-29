<?php

declare(strict_types=1);

namespace App\Domains\Billing\Application\Query;

use App\Domains\Billing\Domain\Model\WriteOff;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * REQUESTED write-offs — the approver worklist (20-billing-and-payments-domain.md
 * §165; ADR-191 G-3), optionally scoped to a facility. `view` gates on the
 * coarse `billing.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: WriteOff::class)]
final readonly class GetPendingWriteOffs
{
    public function __construct(public ?string $facilityId = null)
    {
        if ($facilityId !== null && ! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingWriteOffs::$facilityId must be a UUID (IC §3).');
        }
    }
}
