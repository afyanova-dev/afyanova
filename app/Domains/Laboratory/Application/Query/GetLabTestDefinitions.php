<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Application\Query;

use App\Domains\Laboratory\Domain\Model\LabOrder;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read the tenant's laboratory test catalog, optionally filtered
 * (14-laboratory-domain.md §12, §192; ADR-157 D-G) — a consumer needs it to
 * populate an order picker. Protected at the coarse `laboratory.access` key.
 * Tenant-wide (ADR-157 D-C); NO pagination (catalogs are small — the CP.1
 * `GetProcedureDefinitions` precedent).
 */
#[ProtectedQuery(ability: 'view', subjectType: LabOrder::class)]
final readonly class GetLabTestDefinitions
{
    public function __construct(
        public ?string $category = null,
        public ?bool $active = null,
    ) {}
}
