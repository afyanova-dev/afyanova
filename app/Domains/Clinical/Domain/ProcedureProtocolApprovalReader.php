<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Domain;

use App\Domains\Clinical\Contracts\Dto\ProcedureProtocolApprovalSummary;

interface ProcedureProtocolApprovalReader
{
    /**
     * @return list<ProcedureProtocolApprovalSummary>
     */
    public function activeForFacility(string $facilityId): array;

    public function activeForDefinition(string $facilityId, string $definitionId): ?ProcedureProtocolApprovalSummary;
}
