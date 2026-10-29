<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read the tenant's imaging-service catalog, optionally filtered by modality
 * (15-radiology-domain.md §12; ADR-165 D-H) — a consumer needs it to populate
 * an order picker. Protected at the coarse `radiology.access` key. Tenant-wide;
 * NO pagination.
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetImagingServices
{
    public function __construct(
        public ?string $modalityId = null,
        public ?bool $active = null,
    ) {
        if ($modalityId !== null && ! Str::isUuid($modalityId)) {
            throw new InvalidArgumentException('GetImagingServices::$modalityId must be a UUID or null (IC §3).');
        }
    }
}
