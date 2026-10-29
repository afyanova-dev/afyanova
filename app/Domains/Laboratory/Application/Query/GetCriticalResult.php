<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabCriticalResult;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one critical-result record (ADR-161 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabCriticalResult::class)]
final readonly class GetCriticalResult
{
    public function __construct(public string $criticalResultId)
    {
        if (! Str::isUuid($criticalResultId)) {
            throw new InvalidArgumentException('GetCriticalResult::$criticalResultId must be a UUID (IC §3).');
        }
    }
}
