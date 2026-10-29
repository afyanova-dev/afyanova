<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one surgical case (ADR-150 D-J).
 */
#[ProtectedQuery(ability: 'view', subjectType: SurgicalCase::class)]
final readonly class GetSurgicalCase
{
    public function __construct(public string $caseId)
    {
        if (! Str::isUuid($caseId)) {
            throw new InvalidArgumentException('GetSurgicalCase::$caseId must be a UUID (IC §3).');
        }
    }
}
