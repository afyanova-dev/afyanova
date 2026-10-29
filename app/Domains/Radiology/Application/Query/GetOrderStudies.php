<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingStudy;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All studies for an imaging order, incl. terminal ones — §58 history
 * (15-radiology-domain.md §58, §106; ADR-166 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingStudy::class)]
final readonly class GetOrderStudies
{
    public function __construct(public string $orderId)
    {
        if (! Str::isUuid($orderId)) {
            throw new InvalidArgumentException('GetOrderStudies::$orderId must be a UUID (IC §3).');
        }
    }
}
