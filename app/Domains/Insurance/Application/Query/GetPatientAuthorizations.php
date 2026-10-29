<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\Authorization;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every authorization recorded for a patient — active/effective first, then newest
 * (21-insurance-and-payer-domain.md §56–§65; ADR-202 D-C). `view` gates on the
 * coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: Authorization::class)]
final readonly class GetPatientAuthorizations
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientAuthorizations::$patientId must be a UUID (IC §3).');
        }
    }
}
