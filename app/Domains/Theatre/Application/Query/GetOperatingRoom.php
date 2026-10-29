<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\OperatingRoom;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one operating room (ADR-150 D-J).
 */
#[ProtectedQuery(ability: 'view', subjectType: OperatingRoom::class)]
final readonly class GetOperatingRoom
{
    public function __construct(public string $operatingRoomId)
    {
        if (! Str::isUuid($operatingRoomId)) {
            throw new InvalidArgumentException('GetOperatingRoom::$operatingRoomId must be a UUID (IC §3).');
        }
    }
}
