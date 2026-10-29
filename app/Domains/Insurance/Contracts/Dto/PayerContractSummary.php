<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Payer contract read-model projection (21-insurance-and-payer-domain.md §49; ADR-203).
 * NOT #[TypeScript].
 */
final readonly class PayerContractSummary
{
    public function __construct(
        public string $id,
        public string $contractNumber,
        public string $payerId,
        public string $name,
        public ?string $reference,
        public ?string $facilityId,
        public string $status,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public ?string $terms,
        public ?string $terminationReason,
        public string $createdByUserId,
        public ?string $activatedByUserId,
        public ?string $activatedAt,
        public ?string $terminatedByUserId,
        public ?string $terminatedAt,
        public ?string $expiredAt,
        public int $version,
    ) {}
}
