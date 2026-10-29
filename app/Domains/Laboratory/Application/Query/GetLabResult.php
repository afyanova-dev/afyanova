<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one laboratory result (ADR-159 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabResult::class)]
final readonly class GetLabResult
{
    public function __construct(public string $resultId)
    {
        if (! Str::isUuid($resultId)) {
            throw new InvalidArgumentException('GetLabResult::$resultId must be a UUID (IC §3).');
        }
    }
}
