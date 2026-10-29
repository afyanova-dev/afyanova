<?php

declare(strict_types=1);

namespace App\Domains\BedWard\Application\Query;

use App\Domains\BedWard\Domain\Model\Ward;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Live census (bed counts by status) for a ward (24-bed-and-ward-management-domain.md §10; ADR-148 D-I).
 */
#[ProtectedQuery(ability: 'view', subjectType: Ward::class)]
final readonly class GetWardCensus
{
    public function __construct(public string $wardId)
    {
        if (! Str::isUuid($wardId)) {
            throw new InvalidArgumentException('GetWardCensus::$wardId must be a UUID (IC §3).');
        }
    }
}
