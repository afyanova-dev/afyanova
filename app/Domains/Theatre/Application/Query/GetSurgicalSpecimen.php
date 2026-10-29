<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Application\Query;

use App\Domains\Theatre\Domain\Model\SurgicalSpecimen;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one surgical specimen (ADR-164 D-D).
 */
#[ProtectedQuery(ability: 'view', subjectType: SurgicalSpecimen::class)]
final readonly class GetSurgicalSpecimen
{
    public function __construct(public string $surgicalSpecimenId)
    {
        if (! Str::isUuid($surgicalSpecimenId)) {
            throw new InvalidArgumentException('GetSurgicalSpecimen::$surgicalSpecimenId must be a UUID (IC §3).');
        }
    }
}
