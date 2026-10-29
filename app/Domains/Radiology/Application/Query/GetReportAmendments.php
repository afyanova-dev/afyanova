<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Application\Query;

use App\Domains\Radiology\Domain\Model\RadiologyReport;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A radiology report's amendment history, sequence order (15-radiology-domain.md
 * §96; ADR-168 D-E).
 */
#[ProtectedQuery(ability: 'view', subjectType: RadiologyReport::class)]
final readonly class GetReportAmendments
{
    public function __construct(public string $reportId)
    {
        if (! Str::isUuid($reportId)) {
            throw new InvalidArgumentException('GetReportAmendments::$reportId must be a UUID (IC §3).');
        }
    }
}
