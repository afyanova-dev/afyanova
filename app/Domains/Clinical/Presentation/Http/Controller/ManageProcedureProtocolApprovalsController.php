<?php

declare(strict_types=1);

namespace App\Domains\Clinical\Presentation\Http\Controller;

use App\Domains\Clinical\Contracts\ClinicalApi;
use App\Domains\Clinical\Domain\ProcedureDefinitionReader;
use App\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class ManageProcedureProtocolApprovalsController
{
    public function approve(
        Request $request,
        TenantContext $context,
        ProcedureDefinitionReader $definitions,
        ClinicalApi $clinicalApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->assertCanManage($user);

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $data = $request->validate([
            'definition_id' => ['required', 'uuid'],
            'protocol_reference' => ['required', 'string', 'max:255'],
            'protocol_version' => ['required', 'string', 'max:100'],
        ]);

        $definition = $definitions->summary($data['definition_id']);
        if (! $definition->active) {
            throw ValidationException::withMessages([
                'definition_id' => 'An inactive procedure cannot be approved for direct service.',
            ]);
        }

        $clinicalApi->approveDirectProcedureProtocol(
            $tenantId,
            $facilityId,
            $definition->definitionId,
            $definition->version,
            $data['protocol_reference'],
            $data['protocol_version'],
            (string) $user->getAuthIdentifier(),
        );

        return back()->with('status', 'Direct-service protocol approval recorded for this facility.');
    }

    public function revoke(
        Request $request,
        TenantContext $context,
        ClinicalApi $clinicalApi,
        string $approval,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $this->assertCanManage($user);

        $tenantId = $context->tenantId();
        $facilityId = $context->facilityId();
        abort_if($tenantId === '' || $facilityId === null || $facilityId === '', 403, 'No active facility context.');

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $clinicalApi->revokeDirectProcedureProtocolApproval(
            $tenantId,
            $facilityId,
            $approval,
            $data['reason'],
            (string) $user->getAuthIdentifier(),
        );

        return back()->with('status', 'Direct-service protocol approval revoked.');
    }

    private function assertCanManage(User $user): void
    {
        abort_unless(
            $user->can('clinical.procedures.protocol_manage')
                && $user->can('clinical.procedures.read'),
            403,
            'You cannot manage direct-service protocol approvals.',
        );
    }
}
