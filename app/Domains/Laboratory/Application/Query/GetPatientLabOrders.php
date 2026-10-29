<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All laboratory orders for a patient, tenant-wide (14-laboratory-domain.md
 * §201; ADR-157 D-G) — the `GetPatientReferrals` tenant-wide-scope precedent
 * (patients are not facility-scoped).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetPatientLabOrders
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientLabOrders::$patientId must be a UUID (IC §3).');
        }
    }
}
