<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The unacknowledged critical-result queue at a facility (14-laboratory-domain.md
 * §71, §147, §161; ADR-161 D-G) — feeds clinical follow-up / Notifications.
 */
#[ProtectedQuery(ability: 'view', subjectType: LabCriticalResult::class)]
final readonly class GetPendingCriticalResults
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingCriticalResults::$facilityId must be a UUID (IC §3).');
        }
    }
}
