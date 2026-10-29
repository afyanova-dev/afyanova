<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Domain;

use App\Domains\Theatre\Contracts\Dto\CaseEventSummary;

/**
 * Read-side port for CaseEvent queries (ADR-156 D-E).
 */
interface CaseEventReader
{
    /**
     * A case's timeline, `event_at` order.
     *
     * @return list<CaseEventSummary>
     */
    public function forCase(string $caseId): array;
}
