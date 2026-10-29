<?php

declare(strict_types=1);

namespace App\Domains\HR\Domain;

use App\Domains\HR\Contracts\Dto\PersonDetail;

/**
 * Read-side port for Person queries (25-hr-and-workforce-domain.md §2).
 */
interface PersonReader
{
    public function exists(string $personId): bool;

    public function detail(string $personId): PersonDetail;
}
