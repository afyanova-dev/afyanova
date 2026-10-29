<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Live room-status list for a facility (ADR-150 D-J — the Ward Census
 * precedent). Backend data only (ADR-150 D-L).
 */
#[ProtectedQuery(ability: 'view', subjectType: OperatingRoom::class)]
final readonly class GetOrBoard
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetOrBoard::$facilityId must be a UUID (IC §3).');
        }
    }
}
