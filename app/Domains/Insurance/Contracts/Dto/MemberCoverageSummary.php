<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * One member-coverage record (21-insurance-and-payer-domain.md §14; ADR-199
 * D-C). Returned by `GetMemberCoverage` / `GetPatientCoverages` /
 * `SearchMemberCoverages` via `MemberCoverageReader`. The external identifiers
 * are included — the query is `insurance.access`-gated. NOT #[TypeScript].
 */
final readonly class MemberCoverageSummary
{
    public function __construct(
        public string $memberCoverageId,
        public string $patientId,
        public string $planId,
        public string $payerId,
        public ?string $memberNumber,
        public ?string $policyNumber,
        public ?string $cardNumber,
        public string $relationship,
        public ?string $principalMemberCoverageId,
        public string $priority,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $status,
        public ?string $terminatedAt,
        public ?string $terminationReason,
        public string $recordedAt,
    ) {}
}
