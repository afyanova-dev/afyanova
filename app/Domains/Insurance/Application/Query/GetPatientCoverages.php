<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Application\Query;

use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Every coverage a patient holds — ACTIVE first, then by priority
 * (21-insurance-and-payer-domain.md §20, §21; ADR-199 D-C). `view` gates on the
 * coarse `insurance.access`.
 */
#[ProtectedQuery(ability: 'view', subjectType: MemberCoverage::class)]
final readonly class GetPatientCoverages
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientCoverages::$patientId must be a UUID (IC §3).');
        }
    }
}
