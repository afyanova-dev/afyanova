<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\OperativeNoteSummary;

/**
 * Read-side port for OperativeNote queries (ADR-152 D-G).
 */
interface OperativeNoteReader
{
    public function summary(string $operativeNoteId): OperativeNoteSummary;

    public function forCase(string $caseId): ?OperativeNoteSummary;
}
