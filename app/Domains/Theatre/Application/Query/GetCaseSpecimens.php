<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * All specimens for a surgical case, sequence order (17-theatre-domain.md §131;
 * ADR-164 D-D).
 */
#[ProtectedQuery(ability: 'view', subjectType: SurgicalSpecimen::class)]
final readonly class GetCaseSpecimens
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetCaseSpecimens::$caseId must be a UUID (IC §3).');
        }
    }
}
