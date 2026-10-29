<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Domain;

use App\Domains\Radiology\Contracts\Dto\ImagingStudySummary;

/**
 * Read-side port for ImagingStudy queries (ADR-166 D-G). Returns DTOs only —
 * never an Eloquent model (IC §9).
 */
interface ImagingStudyReader
{
    /**
     * Throws `App\Shared\Domain\NotFound` if the study is absent from the
     * current tenant scope (RLS).
     */
    public function summary(string $studyId): ImagingStudySummary;

    /**
     * All studies for an order, incl. terminal ones — §58 history, sequence by
     * `recorded_at`.
     *
     * @return list<ImagingStudySummary>
     */
    public function forOrder(string $orderId): array;

    /**
     * The radiology worklist — studies at a facility, optionally filtered by
     * status (§74 / §76).
     *
     * @param  list<string>|null  $statuses
     * @return list<ImagingStudySummary>
     */
    public function worklistForFacility(string $facilityId, ?array $statuses = null): array;
}
