<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\SurgicalCase;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * List cases currently READY or IN_PROGRESS at a facility (ADR-150 D-J).
 */
#[ProtectedQuery(ability: 'view', subjectType: SurgicalCase::class)]
final readonly class GetActiveCases
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetActiveCases::$facilityId must be a UUID (IC §3).');
        }
    }
}
