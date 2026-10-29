<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/**
 * Minimal, synchronous, backend read of one supplier (ADR-171 D-J). NOT an
 * event payload. NO bank / payment-execution data (§229).
 *
 * @param  list<string>  $categories
 * @param  list<string>  $allowedStatuses  the lifecycle moves this supplier may
 *                                         take next — the screen offers only
 *                                         these (and the handler re-asserts)
 */
final readonly class SupplierSummary
{
    /**
     * @param  list<string>  $categories
     * @param  list<string>  $allowedStatuses
     */
    public function __construct(
        public string $supplierId,
        public string $code,
        public string $name,
        public ?string $registrationNumber,
        public ?string $contactName,
        public ?string $contactEmail,
        public ?string $contactPhone,
        public ?string $address,
        public ?string $taxIdentifier,
        public ?string $paymentTerms,
        public array $categories,
        public string $status,
        public ?string $statusReason,
        public int $version,
        public array $allowedStatuses,
        public ?string $licenceNumber,
        public ?string $licenceAuthority,
        public ?string $licenceExpiryDate,
        public string $licenceExpiryStatus,
    ) {}
}
