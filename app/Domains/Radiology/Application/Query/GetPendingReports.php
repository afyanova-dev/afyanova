<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The reporting worklist — non-released radiology reports (DRAFT | REVIEWED |
 * VERIFIED) at a facility, in author order (15-radiology-domain.md §77, §78;
 * ADR-167 D-F).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyReport::class)]
final readonly class GetPendingReports
{
    public function __construct(public string $facilityId)
    {
        if (! Str::isUuid($facilityId)) {
            throw new InvalidArgumentException('GetPendingReports::$facilityId must be a UUID (IC §3).');
        }
    }
}
