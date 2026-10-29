<?php

declare(strict_types=1);

namespace App\Domains\Patient\Contracts\Dto;

/**
 * One patient within a merge-review cluster. Display-only read-model for the
 * governed merge console (UI-SPECIFICATIONS §11.2.4; ADR-118/ADR-128) — a
 * privacy-limited projection (identity number, display name, DOB, lifecycle
 * status), never an event payload.
 */
final readonly class MergeCandidateDto
{
    public function __construct(
        public string $patientId,
        public string $patientNumber,
        public string $displayName,
        public ?string $dateOfBirth,
        public string $sex,
        public string $status,
    ) {}
}