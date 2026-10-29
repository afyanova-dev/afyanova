<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Contracts\Dto;

final readonly class ProcedureProtocolApprovalSummary
{
    public function __construct(
        public string $approvalId,
        public string $facilityId,
        public string $definitionId,
        public int $definitionVersion,
        public string $protocolReference,
        public string $protocolVersion,
        public string $approvedByUserId,
        public string $approvedAt,
    ) {}
}
