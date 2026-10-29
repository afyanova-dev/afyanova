<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Contracts\Dto;

/** One supplier invited to an RFQ (ADR-173 D-F). */
final readonly class RfqInvitationSummary
{
    public function __construct(
        public string $supplierId,
        public string $invitedAt,
        public bool $hasQuotation,
    ) {}
}
