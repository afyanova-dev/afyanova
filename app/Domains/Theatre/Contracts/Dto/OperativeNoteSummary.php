<?php

declare(strict_types=1);

namespace App\Domains\Theatre\Contracts\Dto;

/**
 * Summary read of one operative note, including its amendment history
 * (ADR-152 D-G). A protected read model, not an event payload.
 */
final readonly class OperativeNoteSummary
{
    /**
     * @param  array<string, mixed>  $content
     * @param  list<OperativeNoteAmendmentSummary>  $amendments
     */
    public function __construct(
        public string $operativeNoteId,
        public string $facilityId,
        public string $caseId,
        public string $patientId,
        public string $encounterId,
        public array $content,
        public string $status,
        public ?string $reviewedByUserId,
        public ?string $reviewedAt,
        public ?string $signedByUserId,
        public ?string $signedAt,
        public string $createdByUserId,
        public array $amendments,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
}
