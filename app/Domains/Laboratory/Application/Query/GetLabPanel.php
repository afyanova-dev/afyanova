<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Read one laboratory panel and its member tests (14-laboratory-domain.md §14;
 * ADR-162 D-G). Routes through the order policy (the CP.1 catalog-read
 * precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetLabPanel
{
    public function __construct(public string $panelId)
    {
        if (! Str::isUuid($panelId)) {
            throw new InvalidArgumentException('GetLabPanel::$panelId must be a UUID (IC §3).');
        }
    }
}
