<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one imaging order and its requested services (ADR-165 D-H).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetImagingOrder
{
    public function __construct(public string $orderId)
    {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('GetImagingOrder::$orderId must be a UUID (IC §3).');
        }
    }
}
