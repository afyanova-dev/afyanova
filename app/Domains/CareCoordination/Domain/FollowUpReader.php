<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Domain;

use App\Domains\CareCoordination\Contracts\Dto\FollowUpSummary;

/**
 * Read-side port for FollowUp queries (ADR-154 D-F).
 */
interface FollowUpReader
{
    /**
     * PENDING follow-ups at a facility whose [due_from, due_to] window
     * overlaps [$from, $to], due_from order (GetFollowUpsDue — §10).
     *
     * @return list<FollowUpSummary>
     */
    public function dueForFacility(string $facilityId, string $from, string $to): array;
}
