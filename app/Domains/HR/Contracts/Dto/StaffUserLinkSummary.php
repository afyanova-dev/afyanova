<?php

declare(strict_types=1);

namespace App\Domains\HR\Contracts\Dto;

/**
 * Staff-user link summary (25-hr-and-workforce-domain.md §2; Invariant 7.3).
 */
final readonly class StaffUserLinkSummary
{
    public function __construct(
        public string $linkId,
        public string $tenantId,
        public string $staffId,
        public string $userId,
        public string $linkedAt,
        public string $linkedBy,
    ) {}
}
