<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabResult;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The correction / cancellation history of one laboratory result
 * (14-laboratory-domain.md §137; ADR-160 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabResult::class)]
final readonly class GetResultAmendments
{
    public function __construct(public string $resultId)
    {
        if (! Str::isUuid($resultId)) {
            throw new InvalidArgumentException('GetResultAmendments::$resultId must be a UUID (IC §3).');
        }
    }
}
