<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\ImagingOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All imaging orders for a patient, tenant-wide (15-radiology-domain.md §133;
 * ADR-165 D-H) — the `GetPatientLabOrders` tenant-wide-scope precedent
 * (patients are not facility-scoped).
 */
#[ProtectedQuery(ability: 'view', subjectType: ImagingOrder::class)]
final readonly class GetPatientImagingOrders
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientImagingOrders::$patientId must be a UUID (IC §3).');
        }
    }
}
