<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Presentation\Http\Controller;

use App\Domains\Authentication\Application\PlatformAdministrationService;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

final class PlatformAdminController
{
    public function index(PlatformAdministrationService $administration): Response
    {
        return Inertia::render('PlatformAdmin/Index', [
            'tenants' => $administration->tenants(),
            'facilityReferences' => $administration->facilityReferences(),
            'section' => 'overview',
        ]);
    }

    public function tenants(PlatformAdministrationService $administration): Response
    {
        return Inertia::render('PlatformAdmin/Index', [
            'tenants' => $administration->tenants(),
            'facilityReferences' => $administration->facilityReferences(),
            'section' => 'tenants',
        ]);
    }

    public function search(Request $request, PlatformAdministrationService $administration): JsonResponse
    {
        $request->merge(['q' => trim((string) $request->input('q', ''))]);
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:80'],
        ]);

        return response()->json([
            'results' => $administration->search(trim($validated['q'])),
        ]);
    }

    public function createTenantForm(PlatformAdministrationService $administration): Response
    {
        return Inertia::render('PlatformAdmin/Index', [
            'tenants' => $administration->tenants(),
            'facilityReferences' => $administration->facilityReferences(),
            'section' => 'create',
        ]);
    }

    public function showTenant(
        string $tenantId,
        PlatformAdministrationService $administration,
    ): Response {
        $tenant = $administration->tenantDetails($tenantId);
        abort_if($tenant === null, 404);

        return Inertia::render('PlatformAdmin/Tenants/Show', [
            'tenants' => $administration->tenants(),
            'facilityReferences' => $administration->facilityReferences(),
            'tenant' => $tenant,
        ]);
    }

    public function showFacility(
        string $tenantId,
        string $facilityId,
        PlatformAdministrationService $administration,
    ): Response {
        $facility = $administration->facilityDetails($tenantId, $facilityId);
        abort_if($facility === null, 404);

        return Inertia::render('PlatformAdmin/Facilities/Show', [
            'facility' => $facility,
            'facilityReferences' => $administration->facilityReferences(),
        ]);
    }

    public function updateFacility(
        Request $request,
        string $tenantId,
        string $facilityId,
        PlatformAdministrationService $administration,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'string', Rule::exists('org_facility_types', 'code')->where('active', true)],
            'careLevel' => ['required', 'string', Rule::exists('org_care_levels', 'code')->where('active', true)],
            'ownershipType' => ['required', 'string', Rule::exists('org_ownership_types', 'code')],
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED', 'CLOSED'])],
        ]);

        try {
            $administration->updateFacility($tenantId, $facilityId, $validated, (string) $user->getKey());
        } catch (RuntimeException $exception) {
            Log::notice('Platform facility update was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['name' => $exception->getMessage()]);
        }

        return back()->with('status', 'Facility details updated and audited.');
    }

    public function revokeFacilityAdmin(
        Request $request,
        string $tenantId,
        string $facilityId,
        PlatformAdministrationService $administration,
        ScopeChecker $scopeChecker,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $immediate = $administration->requestFacilityAdminRevocation(
                tenantId: $tenantId,
                facilityId: $facilityId,
                assignmentId: $validated['assignment_id'],
                reason: trim($validated['reason']),
                actorId: (string) $user->getKey(),
                scopeChecker: $scopeChecker,
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Facility Admin revocation was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'facility_id' => $facilityId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['assignment_id' => $exception->getMessage()]);
        }

        return back()->with('status', $immediate
            ? 'Emergency sole-administrator revocation was applied immediately and audited.'
            : 'Revocation request submitted. The Facility Admin remains active until an authorized tenant administrator approves it.');
    }

    public function revokeTenantAdmin(
        Request $request,
        string $tenantId,
        PlatformAdministrationService $administration,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'assignment_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $administration->emergencyRevokeTenantAdmin(
                tenantId: $tenantId,
                assignmentId: $validated['assignment_id'],
                reason: trim($validated['reason']),
                actorId: (string) $user->getKey(),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Tenant Admin revocation was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['assignment_id' => $exception->getMessage()]);
        }

        return back()->with('status', 'Emergency Tenant Admin revocation was applied immediately and audited.');
    }

    public function reviewTenantAdminRevocation(
        Request $request,
        string $tenantId,
        string $reviewId,
        PlatformAdministrationService $administration,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $validated = $request->validate([
            'review_notes' => ['required', 'string', 'min:8', 'max:1000'],
        ]);

        try {
            $administration->reviewEmergencyTenantAdminRevocation(
                tenantId: $tenantId,
                reviewId: $reviewId,
                reviewerId: (string) $user->getKey(),
                reviewNotes: trim($validated['review_notes']),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Tenant Admin emergency review was rejected.', [
                'reviewer_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'review_id' => $reviewId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['review_notes' => $exception->getMessage()]);
        }

        return back()->with('status', 'Emergency Tenant Admin revocation reviewed and audited.');
    }

    public function inviteInitialTenantAdmin(
        Request $request,
        PlatformAdministrationService $administration,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'change_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'resend' => ['sometimes', 'boolean'],
        ]);
        $tenantId = (string) $request->route('tenantId');

        try {
            $administration->inviteInitialTenantAdmin(
                tenantId: $tenantId,
                name: trim($validated['name']),
                email: mb_strtolower(trim($validated['email'])),
                changeReference: $validated['change_reference'],
                actorId: (string) $user->getKey(),
                resend: (bool) ($validated['resend'] ?? false),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform Tenant Admin invitation was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return back()->with('status', 'The initial Tenant Admin setup invitation was sent. The link expires in 60 minutes.');
    }

    public function updateTenantStatus(
        Request $request,
        string $tenantId,
        PlatformAdministrationService $administration,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'status' => ['required', Rule::in(['ACTIVE', 'SUSPENDED'])],
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);

        try {
            $sessionsRevoked = $administration->setTenantStatus(
                tenantId: $tenantId,
                status: $validated['status'],
                actorId: (string) $user->getKey(),
                reason: trim($validated['reason']),
            );
        } catch (RuntimeException $exception) {
            Log::notice('Platform tenant status change was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_id' => $tenantId,
                'requested_status' => $validated['status'],
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['status' => $exception->getMessage()]);
        }

        return back()->with('status', $validated['status'] === 'SUSPENDED'
            ? "Tenant suspended; {$sessionsRevoked} active session(s) were revoked and tenant records were preserved."
            : 'Tenant reactivated. Users must sign in again because previous sessions remain revoked.');
    }

    public function createTenant(
        Request $request,
        PlatformAdministrationService $administration,
        OrganizationApi $organizationApi,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'tenant_name' => ['required', 'string', 'max:120'],
            'tenant_slug' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:shared_tenants,slug'],
            'organization_name' => ['required', 'string', 'max:120'],
            'organization_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/'],
            'facility_name' => ['required', 'string', 'max:120'],
            'facility_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9]+(?:_[A-Z0-9]+)*$/'],
            'facility_type' => ['required', 'string', Rule::exists('org_facility_types', 'code')->where('active', true)],
            'care_level' => ['required', 'string', Rule::exists('org_care_levels', 'code')->where('active', true)],
            'ownership_type' => ['required', 'string', Rule::exists('org_ownership_types', 'code')],
        ]);

        try {
            $created = $administration->createTenantWithInitialFacility([
                'tenantName' => trim($validated['tenant_name']),
                'tenantSlug' => $validated['tenant_slug'],
                'organizationName' => trim($validated['organization_name']),
                'organizationCode' => $validated['organization_code'],
                'facilityName' => trim($validated['facility_name']),
                'facilityCode' => $validated['facility_code'],
                'facilityType' => $validated['facility_type'],
                'careLevel' => $validated['care_level'],
                'ownershipType' => $validated['ownership_type'],
            ], (string) $user->getKey(), $organizationApi);
        } catch (RuntimeException $exception) {
            Log::notice('Platform tenant setup was rejected.', [
                'actor_id' => (string) $user->getKey(),
                'tenant_slug' => $validated['tenant_slug'],
                'reason' => $exception->getMessage(),
            ]);
            throw ValidationException::withMessages(['tenant_slug' => $exception->getMessage()]);
        }

        return redirect()
            ->route('platform-admin.tenants.show', ['tenantId' => $created['tenantId']])
            ->with('status', 'Tenant, organization, and initial facility were created. Review the setup and invite its initial Tenant Admin when ready.');
    }
}
