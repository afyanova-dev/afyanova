<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * Summary of a payer remittance advice batch (21-insurance-and-payer-domain.md §98–§103; ADR-205).
 * NOT #[TypeScript].
 */
final readonly class RemittanceSummary
{
    /**
     * @param  list<RemittanceLineSummary>  $lines
     */
    public function __construct(
        public string $remittanceId,
        public string $tenantId,
        public string $facilityId,
        public string $remittanceNumber,
        public string $payerId,
        public string $paymentReference,
        public string $paymentMethod,
        public string $remittanceDate,
        public string $totalAmount,
        public string $reconciledAmount,
        public string $unreconciledAmount,
        public string $status,
        public string $currency,
        public ?string $notes,
        public string $createdByUserId,
        public array $lines,
        public string $occurredAt,
        public string $recordedAt,
    ) {}
}
