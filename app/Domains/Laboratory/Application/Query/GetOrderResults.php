<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All results for a laboratory order — the data behind a laboratory report
 * (14-laboratory-domain.md §105; ADR-159 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabResult::class)]
final readonly class GetOrderResults
{
    public function __construct(public string $orderId)
    {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('GetOrderResults::$orderId must be a UUID (IC §3).');
        }
    }
}
