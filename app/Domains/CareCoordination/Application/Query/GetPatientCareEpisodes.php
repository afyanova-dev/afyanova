<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Application\Query;

use App\Domains\CareCoordination\Domain\Model\CareEpisode;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All care episodes for a patient, tenant-wide (26-care-coordination-domain.md
 * §10; ADR-153 D-F) — the GetPatientReferrals tenant-wide-scope precedent
 * (patients are not facility-scoped).
 */
#[ProtectedQuery(ability: 'view', subjectType: CareEpisode::class)]
final readonly class GetPatientCareEpisodes
{
    public function __construct(public string $patientId)
    {
        if (! Str::isUuid($patientId)) {
            throw new InvalidArgumentException('GetPatientCareEpisodes::$patientId must be a UUID (IC §3).');
        }
    }
}
