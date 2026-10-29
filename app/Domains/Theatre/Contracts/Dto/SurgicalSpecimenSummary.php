<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Summary read of one surgical specimen (ADR-164 D-D). A protected read model
 * for an authorized caller — `description` is present here (the ADR-136
 * deny-list applies to events / audit, not to a gated query result).
 */
final readonly class SurgicalSpecimenSummary
{
    public function __construct(
        public string $surgicalSpecimenId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public int $sequence,
        public string $specimenType,
        public ?string $bodySite,
        public ?string $laterality,
        public ?string $description,
        public string $collectedByUserId,
        public ?string $collectedAt,
        public ?string $destinationLaboratory,
        public ?string $labAccessionRef,
        public string $status,
        public ?string $handedOverByUserId,
        public ?string $handedOverAt,
        public ?string $receivedRecordedAt,
    ) {}
}
