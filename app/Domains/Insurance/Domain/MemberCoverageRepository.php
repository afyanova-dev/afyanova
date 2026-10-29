<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Domain;

use App\Domains\Insurance\Domain\Model\MemberCoverage;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the member-coverage aggregate (IC §9; ADR-199 D-A). The
 * FIRST repository parameter of the member-coverage commands — the bus resolves
 * `MemberCoveragePolicy` from it.
 */
interface MemberCoverageRepository extends Repository
{
    public function getById(string $id): MemberCoverage;

    public function getByIdForUpdate(string $id): MemberCoverage;

    /** True if a coverage row with this id exists in the current tenant scope. */
    public function exists(string $id): bool;
}
