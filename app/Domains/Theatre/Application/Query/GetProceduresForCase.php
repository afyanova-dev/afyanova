<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\SurgicalProcedure;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All procedures for a surgical case, sequence order (ADR-156 D-C).
 */
#[ProtectedQuery(ability: 'view', subjectType: SurgicalProcedure::class)]
final readonly class GetProceduresForCase
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetProceduresForCase::$caseId must be a UUID (IC §3).');
        }
    }
}
