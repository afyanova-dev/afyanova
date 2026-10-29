<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Application\PlatformAdministrationService;
use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FacilityUserInvitationController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        PlatformAdministrationService $administration,
        ScopeChecker $scopeChecker,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'role_key' => ['nullable', 'string', Rule::in([
                FacilityUserRoleCatalog::ADMINISTRATOR,
                FacilityUserRoleCatalog::HR_MANAGER,
                ...array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
            ])],
            'facility_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'change_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $tenantId = $context->tenantId();

        try {
            $administration->inviteFacilityUser(
                tenantId: $tenantId,
                roleKey: $validated['role_key'] ?? 'facility_admin',
                facilityId: $validated['facility_id'],
                name: trim($validated['name']),
                email: mb_strtolower(trim($validated['email'])),
                changeReference: $validated['change_reference'],
                reason: trim($validated['reason']),
                actor: $actor,
                scopeChecker: $scopeChecker,
            );
        } catch (RuntimeException $exception) {
            Log::notice('Facility user invitation was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $tenantId,
                'facility_id' => $validated['facility_id'],
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        $roleKey = $validated['role_key'] ?? FacilityUserRoleCatalog::ADMINISTRATOR;
        $roleLabel = FacilityUserRoleCatalog::label($roleKey) ?? 'facility user';

        // Ordinary operational roles grant directly when the inviter holds
        // identity-grant authority; otherwise the inviter can approve the
        // resulting request from the staff record or Role approvals.
        if ($roleKey === FacilityUserRoleCatalog::ADMINISTRATOR) {
            return back()->with('status', 'Setup invitation sent. After account setup, you can approve the Facility Admin access request from the Role approvals page.');
        }

        if ($roleKey === FacilityUserRoleCatalog::HR_MANAGER) {
            return back()->with('status', 'Setup invitation sent. After account setup, approve the HR / Staff Manager request from the Role approvals page.');
        }

        if (array_key_exists($roleKey, FacilityUserRoleCatalog::STAFF_ROLES)) {
            return back()->with('status', "Setup invitation sent. After account setup, {$roleLabel} access will be active immediately if you hold identity-grant authority for the facility, otherwise approve the request from the linked staff record or Role approvals.");
        }

        return back()->with('status', "Setup invitation sent. After account setup, approve the {$roleLabel} access request from the Role approvals page.");
    }

    public function resend(
        Request $request,
        string $invitationId,
        TenantContext $context,
        PlatformAdministrationService $administration,
        ScopeChecker $scopeChecker,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $validated = $request->validate([
            'change_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $tenantId = $context->tenantId();
        $invitation = $administration->facilityInvitationForResend($tenantId, $invitationId);
        abort_unless($invitation !== null, 404);
        if (! $invitation->canResend()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation cannot be resent. Only an unused, unaccepted setup invitation for an active account that has not verified its email can be resent.',
            ]);
        }

        $decodedRoleKeys = is_string($invitation->roleKeys)
            ? json_decode($invitation->roleKeys, true)
            : null;
        $roleKeys = [];
        if (is_array($decodedRoleKeys)) {
            foreach ($decodedRoleKeys as $roleKey) {
                if (! is_string($roleKey)) {
                    throw ValidationException::withMessages([
                        'invitation' => 'This invitation cannot be resent because its role data is invalid.',
                    ]);
                }
                $roleKeys[] = $roleKey;
            }
        }
        if ($roleKeys === []) {
            $roleKeys = [
                (string) ($invitation->roleKey
                    ?? ($invitation->purpose === 'FACILITY_ADMIN'
                        ? FacilityUserRoleCatalog::ADMINISTRATOR
                        : '')),
            ];
        }

        try {
            $administration->inviteFacilityUser(
                tenantId: $tenantId,
                roleKey: $roleKeys,
                facilityId: $invitation->facilityId,
                name: (string) $invitation->name,
                email: mb_strtolower((string) $invitation->email),
                changeReference: $validated['change_reference'],
                reason: trim($validated['reason']),
                actor: $actor,
                scopeChecker: $scopeChecker,
            );
        } catch (RuntimeException $exception) {
            Log::notice('Facility user invitation resend was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $tenantId,
                'facility_id' => $invitation->facilityId,
                'invitation_id' => $invitationId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return back()->with('status', 'Setup invitation resent. The new link expires in 60 minutes.');
    }
}
