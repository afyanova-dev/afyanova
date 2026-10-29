<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Summary read of one anaesthesia record, including its amendment history
 * (ADR-155 D-F). A protected read model, not an event payload.
 */
final readonly class AnaesthesiaRecordSummary
{
    /**
     * @param  array<string, mixed>  $content
     * @param  list<AnaesthesiaRecordAmendmentSummary>  $amendments
     */
    public function __construct(
        public string $anaesthesiaRecordId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public string $anaesthesiaType,
        public ?string $providerUserId,
        public ?string $startedAt,
        public ?string $endedAt,
        public array $content,
        public ?string $outcome,
        public string $status,
        public ?string $signedByUserId,
        public ?string $signedAt,
        public string $createdByUserId,
        public array $amendments,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
