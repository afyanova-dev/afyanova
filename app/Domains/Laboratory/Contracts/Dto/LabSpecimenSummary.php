<?php

declare(strict_types=1);

namespace App\Domains\Laboratory\Contracts\Dto;

/**
 * Minimal summary read of one laboratory specimen (ADR-158 D-G). A protected
 * read model, not an event payload — `rejectionDetail` is present here (the
 * ADR-136 deny-list applies to events / audit, not to a gated query result).
 */
final readonly class LabSpecimenSummary
{
    public function __construct(
        public string $specimenId,
        public string $orderId,
        public string $patientId,
        public string $facilityId,
        public string $accessionNumber,
        public string $specimenType,
        public ?string $containerType,
        public ?string $collectionLocation,
        public string $collectedByUserId,
        public ?string $collectedAt,
        public ?string $receivedByUserId,
        public ?string $receivedAt,
        public string $status,
        public ?string $rejectionReasonCode,
        public ?string $rejectionDetail,
        public ?string $recollectionRequestedAt,
        public ?string $recollectionOfSpecimenId,
    ) {}
}
