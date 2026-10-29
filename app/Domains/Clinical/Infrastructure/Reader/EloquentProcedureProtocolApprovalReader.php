<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Reader;

use App\Domains\Clinical\Contracts\Dto\ProcedureProtocolApprovalSummary;
use App\Domains\Clinical\Domain\ProcedureProtocolApprovalReader;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

final readonly class EloquentProcedureProtocolApprovalReader implements ProcedureProtocolApprovalReader
{
    public function __construct(private TenantContext $context) {}

    public function activeForFacility(string $facilityId): array
    {
        return DB::table('clin_procedure_protocol_approvals')
            ->where('tenant_id', $this->context->tenantId())
            ->where('facility_id', $facilityId)
            ->whereNull('revoked_at')
            ->orderBy('procedure_definition_id')
            ->get()
            ->map(fn (object $row): ProcedureProtocolApprovalSummary => $this->toSummary($row))
            ->all();
    }

    public function activeForDefinition(string $facilityId, string $definitionId): ?ProcedureProtocolApprovalSummary
    {
        $row = DB::table('clin_procedure_protocol_approvals')
            ->where('tenant_id', $this->context->tenantId())
            ->where('facility_id', $facilityId)
            ->where('procedure_definition_id', $definitionId)
            ->whereNull('revoked_at')
            ->first();

        return $row === null ? null : $this->toSummary($row);
    }

    private function toSummary(object $row): ProcedureProtocolApprovalSummary
    {
        return new ProcedureProtocolApprovalSummary(
            approvalId: (string) $row->id,
            facilityId: (string) $row->facility_id,
            definitionId: (string) $row->procedure_definition_id,
            definitionVersion: (int) $row->procedure_definition_version,
            protocolReference: (string) $row->protocol_reference,
            protocolVersion: (string) $row->protocol_version,
            approvedByUserId: (string) $row->approved_by_user_id,
            approvedAt: (string) $row->approved_at,
        );
    }
}
