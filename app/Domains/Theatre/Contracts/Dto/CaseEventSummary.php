<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * One row on a surgical case's intra-op timeline (ADR-156 D-E). A protected
 * read model.
 */
final readonly class CaseEventSummary
{
    public function __construct(
        public string $caseEventId,
        public string $facilityId,
        public string $caseId,
        public string $eventType,
        public string $eventAt,
        public ?string $note,
        public string $recordedByUserId,
        public ?string $createdAt = null,
    ) {}
}
