<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Recent QC runs at a facility — the QC board (14-laboratory-domain.md §74;
 * ADR-163 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabQcRun::class)]
final readonly class GetQcRuns
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetQcRuns::$facilityId must be a UUID (IC §3).');
        }
    }
}
