<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Dto;

/**
 * One episode ↔ encounter membership link (ADR-153 D-F). A protected read
 * model — the ADR-136 deny-list applies to events/audit, not to a gated
 * query result for an authorized caller.
 */
final readonly class CareEpisodeEncounterSummary
{
    public function __construct(
        public string $careEpisodeEncounterId,
        public string $careEpisodeId,
        public string $encounterId,
        public string $patientId,
        public ?string $role,
        public string $status,
        public ?string $addedReason,
        public string $addedByUserId,
        public ?string $addedAt,
        public ?string $detachReason,
        public ?string $detachedByUserId,
        public ?string $detachedAt,
    ) {}
}
