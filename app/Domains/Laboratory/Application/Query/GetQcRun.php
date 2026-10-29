<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabQcRun;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one QC run (ADR-163 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabQcRun::class)]
final readonly class GetQcRun
{
    public function __construct(public string $qcRunId)
    {
        if (! Str::isUuid($qcRunId)) {
            throw new InvalidArgumentException('GetQcRun::$qcRunId must be a UUID (IC §3).');
        }
    }
}
