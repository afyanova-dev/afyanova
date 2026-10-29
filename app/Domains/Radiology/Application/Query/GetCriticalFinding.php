<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyCriticalFinding;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one radiology critical finding (ADR-169 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyCriticalFinding::class)]
final readonly class GetCriticalFinding
{
    public function __construct(public string $criticalFindingId)
    {
        if (! Str::isUuid($criticalFindingId)) {
            throw new InvalidArgumentException('GetCriticalFinding::$criticalFindingId must be a UUID (IC §3).');
        }
    }
}
