<?php

declare(strict_types=1);

namespace App\Domains\CareCoordination\Contracts\Dto;

/**
 * Summary read of one care episode, including its LINKED encounter list
 * (ADR-153 D-F). A protected read model, not an event payload.
 */
final readonly class CareEpisodeSummary
{
    /**
     * @param  list<CareEpisodeEncounterSummary>  $encounters
     */
    public function __construct(
        public string $careEpisodeId,
        public string $facilityId,
        public string $patientId,
        public string $type,
        public ?string $subtype,
        public string $status,
        public bool $isOverride,
        public string $createdByUserId,
        public ?string $openedAt,
        public ?string $closedAt,
        public array $encounters,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
