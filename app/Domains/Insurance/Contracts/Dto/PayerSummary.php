<?php

declare(strict_types=1);

namespace App\Domains\Insurance\Contracts\Dto;

/**
 * One payer master entry (21-insurance-and-payer-domain.md §9; ADR-198 D-I).
 * Returned by `GetPayer` / `SearchPayers` via `PayerReader`. NOT #[TypeScript].
 */
final readonly class PayerSummary
{
    public function __construct(
        public string $payerId,
        public string $code,
        public string $name,
        public string $payerType,
        public string $status,
        public ?string $statusReason,
        public ?string $contactName,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public string $recordedAt,
    ) {}
}
