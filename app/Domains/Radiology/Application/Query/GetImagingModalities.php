<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read the tenant's imaging modality catalog, optionally filtered
 * (15-radiology-domain.md §13; ADR-165 D-H). Protected at the coarse
 * `radiology.access` key. Tenant-wide; NO pagination (catalogs are small — the
 * CP.1 / Lab.1 precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetImagingModalities
{
    public function __construct(
        public ?string $category = null,
        public ?bool $active = null,
    ) {}
}
