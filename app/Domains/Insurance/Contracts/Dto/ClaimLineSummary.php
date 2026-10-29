<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * One claim line summary (21-insurance-and-payer-domain.md §78; ADR-204).
 * NOT #[TypeScript].
 */
final readonly class ClaimLineSummary
{
    public function __construct(
        public string $lineId,
        public string $claimId,
        public string $chargeId,
        public string $serviceCategory,
        public string $serviceCode,
        public string $serviceDescription,
        public string $serviceDate,
        public string $quantity,
        public string $unitPrice,
        public string $claimedAmount,
        public ?string $authorizationId,
        public ?string $diagnosisCode,
        public int $lineNumber,
        public bool $lineActive,
    ) {}
}
