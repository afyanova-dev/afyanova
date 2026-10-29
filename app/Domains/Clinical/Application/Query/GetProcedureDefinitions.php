<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Application\Query;

use App\Domains\Clinical\Domain\Model\ClinicalProcedure;
use App\Shared\Bus\ProtectedQuery;

/**
 * Read the tenant's procedure catalog, optionally filtered
 * (13-clinical-procedures-domain.md §12; ADR-146 D-Q) — a consumer needs it to
 * populate a picker. Protected at the coarse `clinical.procedures.read` key.
 * Tenant-wide (ADR-146 D-C); NO pagination (catalogs are small).
 */
#[ProtectedQuery(ability: 'view', subjectType: ClinicalProcedure::class)]
final readonly class GetProcedureDefinitions
{
    public function __construct(
        public ?string $category = null,
        public ?bool $active = null,
    ) {}
}
