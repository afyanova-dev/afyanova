<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Infrastructure\Writer;

use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ProcedureProtocolApprovalWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approve(
        string $tenantId,
        string $facilityId,
        string $definitionId,
        int $definitionVersion,
        string $protocolReference,
        string $protocolVersion,
        string $actorId,
    ): void {
        DB::transaction(function () use (
            $tenantId,
            $facilityId,
            $definitionId,
            $definitionVersion,
            $protocolReference,
            $protocolVersion,
            $actorId,
        ): void {
            $alreadyApproved = DB::table('clin_procedure_protocol_approvals')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('procedure_definition_id', $definitionId)
                ->whereNull('revoked_at')
                ->exists();

            if ($alreadyApproved) {
                throw ValidationException::withMessages([
                    'definition_id' => 'Revoke the current approval before approving a replacement protocol.',
                ]);
            }

            $approvalId = (string) Str::uuid7();
            $reference = trim($protocolReference);
            $version = trim($protocolVersion);
            DB::table('clin_procedure_protocol_approvals')->insert([
                'id' => $approvalId,
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'procedure_definition_id' => $definitionId,
                'procedure_definition_version' => $definitionVersion,
                'protocol_reference' => $reference,
                'protocol_version' => $version,
                'approved_by_user_id' => $actorId,
                'approved_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->audit->record(
                action: 'procedures.protocol_approval_granted',
                actorId: $actorId,
                targetType: 'procedure_protocol_approval',
                targetId: $approvalId,
                metadata: [
                    'definition_id' => $definitionId,
                    'definition_version' => $definitionVersion,
                    'protocol_reference' => $reference,
                    'protocol_version' => $version,
                ],
            );
        });
    }

    public function revoke(string $tenantId, string $facilityId, string $approvalId, string $reason, string $actorId): void
    {
        DB::transaction(function () use ($tenantId, $facilityId, $approvalId, $reason, $actorId): void {
            $record = DB::table('clin_procedure_protocol_approvals')
                ->where('tenant_id', $tenantId)
                ->where('facility_id', $facilityId)
                ->where('id', $approvalId)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->first();
            abort_if($record === null, 404);

            $reason = trim($reason);
            DB::table('clin_procedure_protocol_approvals')
                ->where('id', $approvalId)
                ->update([
                    'revoked_by_user_id' => $actorId,
                    'revoked_at' => now(),
                    'revocation_reason' => $reason,
                    'updated_at' => now(),
                ]);

            $this->audit->record(
                action: 'procedures.protocol_approval_revoked',
                actorId: $actorId,
                targetType: 'procedure_protocol_approval',
                targetId: $approvalId,
                reason: $reason,
                metadata: [
                    'definition_id' => (string) $record->procedure_definition_id,
                    'protocol_reference' => (string) $record->protocol_reference,
                    'protocol_version' => (string) $record->protocol_version,
                ],
            );
        });
    }
}
