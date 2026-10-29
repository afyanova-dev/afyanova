<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one laboratory order and its requested tests (ADR-157 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetLabOrder
{
    public function __construct(public string $orderId)
    {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('GetLabOrder::$orderId must be a UUID (IC §3).');
        }
    }
}
