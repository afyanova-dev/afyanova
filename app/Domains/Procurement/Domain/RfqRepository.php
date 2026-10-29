<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Domain;

use App\Domains\Procurement\Domain\Model\Rfq;
use App\Shared\Domain\Repository;

/** Write-side port for the Rfq aggregate (IC §9; ADR-173 D-B). */
interface RfqRepository extends Repository
{
    public function getById(string $id): Rfq;

    public function getByIdForUpdate(string $id): Rfq;
}
