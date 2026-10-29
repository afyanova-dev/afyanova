<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\Referral;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All referrals for a patient, tenant-wide (26-care-coordination-domain.md
 * §10; ADR-151 D-I) — the SearchPatients tenant-wide-scope precedent
 * (patients are not facility-scoped).
 */
#[ProtectedQuery(ability: 'view', subjectType: Referral::class)]
final readonly class GetPatientReferrals
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientReferrals::$patientId must be a UUID (IC §3).');
        }
    }
}
