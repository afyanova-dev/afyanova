<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The unacknowledged critical findings at a facility (15-radiology-domain.md
 * §77, §101, §161; ADR-169 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyCriticalFinding::class)]
final readonly class GetPendingCriticalFindings
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingCriticalFindings::$facilityId must be a UUID (IC §3).');
        }
    }
}
