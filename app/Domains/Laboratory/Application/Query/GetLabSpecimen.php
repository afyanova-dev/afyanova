<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabSpecimen;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one laboratory specimen (ADR-158 D-G).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabSpecimen::class)]
final readonly class GetLabSpecimen
{
    public function __construct(public string $specimenId)
    {
        if (! Str::isUuid($specimenId)) {
            throw new InvalidArgumentException('GetLabSpecimen::$specimenId must be a UUID (IC §3).');
        }
    }
}
