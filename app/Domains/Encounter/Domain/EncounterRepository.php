<?php

declare(strict_types=1);

namespace App\Domains\Encounter\Domain;

use App\Domains\Encounter\Domain\Model\Encounter;
use App\Shared\Domain\Repository;

/**
 * Write-side port for the Encounter aggregate (IMPLEMENTATION-CONTRACTS.md §9).
 * Implementation: Infrastructure/Repository/EloquentEncounterRepository, bound
 * in EncounterServiceProvider. Its FQCN sits directly under Domain/ (not
 * Domain/Repository/) so App\Shared\Bus\Middleware\AuthorizeCommand (IC §5.1)
 * can derive App\Domains\Encounter\Domain\Model\Encounter from it for the
 * class-level `create` policy check.
 *
 * Return types narrow the base `Repository` (which returns `AggregateRoot`) to
 * `Encounter` — a covariant refinement.
 */
interface EncounterRepository extends Repository
{
    public function getById(string $id): Encounter;

    public function getByIdForUpdate(string $id): Encounter;
}
