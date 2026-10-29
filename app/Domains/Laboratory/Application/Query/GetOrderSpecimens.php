<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All specimens for a laboratory order, oldest first — including rejected ones
 * (14-laboratory-domain.md §35; ADR-158 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabSpecimen::class)]
final readonly class GetOrderSpecimens
{
    public function __construct(public string $orderId)
    {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('GetOrderSpecimens::$orderId must be a UUID (IC §3).');
        }
    }
}
