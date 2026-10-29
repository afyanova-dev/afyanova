<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one imaging service catalog entry by id (15-radiology-domain.md §12;
 * ADR-165 D-H). Protected at the coarse `radiology.access` key.
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetImagingServiceDefinition
{
    public function __construct(public string $serviceId)
    {
        if (! Str::isUuid($serviceId)) {
            throw new InvalidArgumentException('GetImagingServiceDefinition::$serviceId must be a UUID (IC §3).');
        }
    }
}
