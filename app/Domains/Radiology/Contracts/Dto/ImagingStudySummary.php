<?php

declare(strict_types=1);

namespace App\Domains\Radiology\Contracts\Dto;

/**
 * Summary read of one imaging study (ADR-166 D-G). A protected read model, not
 * an event payload — `outcomeDetail` is present here (the ADR-136 deny-list
 * applies to events / audit, not to a gated query result for an authorized
 * caller).
 */
final readonly class ImagingStudySummary
{
    public function __construct(
        public string $imagingStudyId,
        public string $facilityId,
        public string $orderId,
        public string $orderItemId,
        public string $patientId,
        public string $encounterId,
        public string $accessionNumber,
        public string $modalityCode,
        public string $serviceCode,
        public ?string $bodySite,
        public ?string $laterality,
        public string $status,
        public ?string $scheduledFor,
        public ?string $appointmentRef,
        public ?string $equipmentRef,
        public ?string $verifiedByUserId,
        public ?string $verifiedAt,
        public ?string $performedByUserId,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $outcomeStatusCode,
        public ?string $outcomeDetail,
        public ?string $repeatOfStudyId,
    ) {}
}
