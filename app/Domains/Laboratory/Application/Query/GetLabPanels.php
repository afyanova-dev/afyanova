<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read the tenant's laboratory panel catalog (14-laboratory-domain.md §14, §192;
 * ADR-162 D-G) — a consumer needs it to populate an order picker.
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetLabPanels
{
    public function __construct(public ?bool $active = null) {}
}
