<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Payer tariff read-model projection (21-insurance-and-payer-domain.md §51–§55; ADR-203).
 * NOT #[TypeScript].
 */
final readonly class PayerTariffSummary
{
    public function __construct(
        public string $id,
        public string $contractId,
        public string $payerId,
        public string $serviceCategory,
        public string $amount,
        public string $currency,
        public string $effectiveFrom,
        public ?string $effectiveTo,
        public string $status,
        public ?string $createdByUserId,
        public int $version,
    ) {}
}
