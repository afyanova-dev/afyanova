<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one imaging modality catalog entry by id (15-radiology-domain.md §13;
 * ADR-165 D-H). Protected at the coarse `radiology.access` key (the CP.1 /
 * Lab.1 `GetProcedureDefinition` precedent — the catalog reads route through
 * the order policy, there is no separate catalog policy in Rad.1).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetImagingModality
{
    public function __construct(public string $modalityId)
    {
        if (! Str::isUuid($modalityId)) {
            throw new InvalidArgumentException('GetImagingModality::$modalityId must be a UUID (IC §3).');
        }
    }
}
