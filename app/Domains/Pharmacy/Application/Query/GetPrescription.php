<?php

declare(strict_types=1);

namespace App\Domains\Pharmacy\Application\Query;

use App\Domains\Pharmacy\Domain\Model\Prescription;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one Prescription record (16-pharmacy-domain.md §19; ADR-181 D-F).
 * `view` gates on the coarse `pharmacy.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Prescription::class)]
final readonly class GetPrescription
{
    public function __construct(public string $prescriptionId)
    {
        if (! Str::isUuid($prescriptionId)) {
            throw new InvalidArgumentException('GetPrescription::$prescriptionId must be a UUID (IC §3).');
        }
    }
}
