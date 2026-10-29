<?php

declare(strict_types=1);

namespace App\Domains\Organization\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Domains\Organization\Domain\Enum\CapabilityState;
use App\Domains\Organization\Domain\Service\CapabilityDependencyValidator;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Capability\FacilityCapabilities;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class AdminOrganizationController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $visibleFacilityIds = [];
        $canManage = false;
        $organizationIds = [];

        foreach ($organizationAdmin->candidateFacilities($context->tenantId()) as $candidateFacility) {
            $facilityId = $candidateFacility['id'];
            $permissions = $scopeChecker->effectivePermissions($user, $facilityId);
            if (! in_array('organization.facilities.read', $permissions, true)) {
                continue;
            }

            $visibleFacilityIds[] = $facilityId;
            $organizationIds[$candidateFacility['organizationId']] = true;
            $canManage = $canManage
                || in_array('organization.facilities.manage', $permissions, true);
        }

        abort_if($visibleFacilityIds === [], 403, 'You are not authorized to view organization facilities.');

        $activeOrganizations = $organizationAdmin->activeOrganizations($context->tenantId());
        $activeOrganizationId = count($activeOrganizations) === 1
            ? $activeOrganizations[0]['id']
            : null;
        $canCreateFacility = $activeOrganizationId !== null
            && isset($organizationIds[$activeOrganizationId])
            && in_array(
                'organization.facilities.manage',
                $scopeChecker->effectiveOrganizationPermissions($user, $activeOrganizationId),
                true,
            );

        $facilities = $organizationAdmin->facilitySummaries($context->tenantId(), $visibleFacilityIds);
        $facilityStructures = [];
        foreach ($visibleFacilityIds as $visibleFacilityId) {
            $facilityStructures[$visibleFacilityId] = $this->facilityPanelData(
                $visibleFacilityId,
                $context->tenantId(),
                $scopeChecker->effectivePermissions($user, $visibleFacilityId),
                $organizationAdmin,
            );
        }

        $selectedFacilityId = $request->query('facility_id');
        $selectedFacility = null;
        if ($selectedFacilityId !== null) {
            abort_unless(is_string($selectedFacilityId), 404);
            abort_unless(array_key_exists($selectedFacilityId, $facilityStructures), 403);
            $selectedFacility = $facilityStructures[$selectedFacilityId];
        }

        return Inertia::render('Admin/Organization/Index', [
            'facilities' => $facilities,
            'facilityStructures' => $facilityStructures,
            'canManage' => $canManage,
            'selectedFacility' => $selectedFacility,
            'canCreateFacility' => $canCreateFacility,
            'facilityTypes' => $canCreateFacility
                ? $organizationAdmin->facilityTypes()
                : [],
            'careLevels' => $canCreateFacility
                ? $organizationAdmin->careLevels()
                : [],
            'ownershipTypes' => $canCreateFacility
                ? $organizationAdmin->ownershipTypes()
                : [],
            'totalFacilities' => count($facilities),
            'activeFacilities' => count(array_filter(
                $facilities,
                static fn (array $facility): bool => $facility['status'] === 'ACTIVE',
            )),
        ]);
    }

    /**
     * @param  list<string>  $permissions
     * @return array<string, mixed>
     */
    private function facilityPanelData(
        string $facilityId,
        string $tenantId,
        array $permissions,
        OrganizationAdminApi $organizationAdmin,
    ): array {
        abort_unless(in_array('organization.facilities.read', $permissions, true), 403);
        $panel = $organizationAdmin->facilityPanel($tenantId, $facilityId);
        abort_if($panel === null, 404);
        $facilityIsActive = (bool) $panel['facilityIsActive'];
        unset($panel['facilityIsActive']);

        return [
            ...$panel,
            'canManageStructure' => $facilityIsActive
                && in_array('organization.structure.manage', $permissions, true),
            'canManageCapabilities' => $facilityIsActive
                && in_array('organization.capabilities.manage', $permissions, true),
        ];
    }

    public function storeDepartment(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('org_departments', 'code')
                    ->where('facility_id', $request->string('facility_id')->toString()),
            ],
            'kind' => ['nullable', 'string', 'max:100'],
        ], [
            'code.unique' => 'This code is already in use at this facility.',
            'code.regex' => 'Use uppercase letters, numbers, hyphens, or underscores.',
        ]);

        $tenantId = $context->tenantId();
        $facilityId = (string) $validated['facility_id'];
        abort_unless(
            $organizationAdmin->activeFacilityInOrganization($tenantId, $facilityId),
            422,
            'Select an active facility in the active organization.',
        );
        abort_unless(
            in_array(
                'organization.structure.manage',
                $scopeChecker->effectivePermissions($actor, $facilityId),
                true,
            ),
            403,
            'You are not authorized to manage this facility structure.',
        );

        $organizationAdmin->createDepartment(
            $tenantId,
            $facilityId,
            $validated['name'],
            $validated['code'],
            $validated['kind'] ?? null,
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', "Department {$validated['name']} added.");
    }

    public function storeUnit(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'department_id' => ['required', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('org_units', 'code')
                    ->where('facility_id', $request->string('facility_id')->toString()),
            ],
            'unit_type' => ['nullable', 'string', 'max:100'],
        ], [
            'code.unique' => 'This code is already in use at this facility.',
            'code.regex' => 'Use uppercase letters, numbers, hyphens, or underscores.',
        ]);

        $tenantId = $context->tenantId();
        $facilityId = (string) $validated['facility_id'];
        $departmentId = (string) $validated['department_id'];
        abort_unless(
            $organizationAdmin->activeDepartmentInFacility($tenantId, $facilityId, $departmentId),
            422,
            'Select an active department in the selected active facility.',
        );
        abort_unless(
            in_array(
                'organization.structure.manage',
                $scopeChecker->effectivePermissions($actor, $facilityId),
                true,
            ),
            403,
            'You are not authorized to manage this facility structure.',
        );

        $organizationAdmin->createUnit(
            $tenantId,
            $facilityId,
            $departmentId,
            $validated['name'],
            $validated['code'],
            ($validated['unit_type'] ?? null) ?: 'GENERIC',
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', "Unit {$validated['name']} added.");
    }

    public function store(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $tenantId = $context->tenantId();
        $activeOrganizations = $organizationAdmin->activeOrganizationIds($tenantId);
        abort_unless(count($activeOrganizations) === 1, 422, 'Facility creation requires exactly one active organization.');

        $organizationId = $activeOrganizations[0];
        abort_unless(
            in_array(
                'organization.facilities.manage',
                $scopeChecker->effectiveOrganizationPermissions($actor, $organizationId),
                true,
            ),
            403,
            'Only an organization-scoped administrator may create facilities.',
        );

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('org_facilities', 'code')->where('tenant_id', $tenantId),
            ],
            'facility_type' => [
                'required',
                'string',
                Rule::exists('org_facility_types', 'code')->where('active', true),
            ],
            'care_level' => [
                'required',
                'string',
                Rule::exists('org_care_levels', 'code')->where('active', true),
            ],
            'ownership_type' => ['required', 'string', Rule::exists('org_ownership_types', 'code')],
        ]);

        $organizationAdmin->createFacility(
            $tenantId,
            $organizationId,
            $validated['name'],
            $validated['code'],
            $validated['facility_type'],
            $validated['care_level'],
            $validated['ownership_type'],
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization');
    }

    public function storeRoom(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('org_rooms', 'code')
                    ->where('facility_id', $request->string('facility_id')->toString()),
            ],
            'room_type' => ['nullable', 'string', 'max:100'],
        ], [
            'code.unique' => 'This code is already in use at this facility.',
            'code.regex' => 'Use uppercase letters, numbers, hyphens, or underscores.',
        ]);

        $tenantId = $context->tenantId();
        $facilityId = (string) $validated['facility_id'];
        $unitId = $validated['unit_id'] ?? null;
        abort_unless(
            $organizationAdmin->activeFacilityInOrganization($tenantId, $facilityId),
            422,
            'Select an active facility in the active organization.',
        );
        abort_unless(
            in_array(
                'organization.structure.manage',
                $scopeChecker->effectivePermissions($actor, $facilityId),
                true,
            ),
            403,
            'You are not authorized to manage this facility structure.',
        );

        if ($unitId !== null) {
            abort_unless(
                $organizationAdmin->activeUnitInFacility($tenantId, $facilityId, $unitId),
                422,
                'Select an active unit in the selected facility.',
            );
        }

        $organizationAdmin->createRoom(
            $tenantId,
            $facilityId,
            $unitId,
            $validated['name'],
            $validated['code'],
            ($validated['room_type'] ?? null) ?: 'GENERIC',
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', "Room {$validated['name']} added.");
    }

    public function updateDepartment(Request $request, string $department, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->updateStructureRecord($request, 'department', $department, $context, $scopeChecker, $organizationAdmin);
    }

    public function updateUnit(Request $request, string $unit, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->updateStructureRecord($request, 'unit', $unit, $context, $scopeChecker, $organizationAdmin);
    }

    public function updateRoom(Request $request, string $room, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->updateStructureRecord($request, 'room', $room, $context, $scopeChecker, $organizationAdmin);
    }

    public function setDepartmentStatus(Request $request, string $department, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->setStructureRecordStatus($request, 'department', $department, $context, $scopeChecker, $organizationAdmin);
    }

    public function setUnitStatus(Request $request, string $unit, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->setStructureRecordStatus($request, 'unit', $unit, $context, $scopeChecker, $organizationAdmin);
    }

    public function setRoomStatus(Request $request, string $room, TenantContext $context, ScopeChecker $scopeChecker, OrganizationAdminApi $organizationAdmin): RedirectResponse
    {
        return $this->setStructureRecordStatus($request, 'room', $room, $context, $scopeChecker, $organizationAdmin);
    }

    public function setCapabilityState(
        Request $request,
        string $capability,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CapabilityDependencyValidator $validator,
        FacilityCapabilities $cache,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'state' => ['required', Rule::in(['ENABLED', 'SUSPENDED', 'DISABLED'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $tenantId = $context->tenantId();
        $facilityId = (string) $validated['facility_id'];
        $code = strtoupper(trim($capability));
        $state = CapabilityState::from((string) $validated['state']);
        $reason = trim((string) ($validated['reason'] ?? ''));

        abort_unless(
            $organizationAdmin->activeFacilityInOrganization($tenantId, $facilityId),
            422,
            'Select an active facility in the active organization.',
        );
        abort_unless(
            in_array('organization.capabilities.manage', $scopeChecker->effectivePermissions($actor, $facilityId), true),
            403,
            'You are not authorized to manage this facility capabilities.',
        );
        abort_unless($organizationAdmin->capabilityIsRegistered($code), 422, "Capability '{$code}' is not in the registry.");
        abort_if($state === CapabilityState::Suspended && $reason === '', 422, 'A reason is required when suspending a capability.');

        if ($state === CapabilityState::Enabled) {
            $missing = $validator->missingDependencies(
                $code,
                $organizationAdmin->enabledCapabilityCodes($facilityId),
                $organizationAdmin->capabilityDependencyMap(),
            );
            abort_if($missing !== [], 422, "Cannot enable {$code}: missing dependencies ".implode(', ', $missing).'.');
        }

        // Plan-limit checkpoint: a future tenant-plan entitlement check plugs
        // in here — the single write path shared with the console command —
        // before mutating.

        $organizationAdmin->setFacilityCapabilityState(
            $tenantId,
            $facilityId,
            $code,
            $state->value,
            $reason === '' ? null : $reason,
            (string) $actor->getKey(),
        );

        $cache->flush($facilityId);

        $pastTense = $state === CapabilityState::Enabled ? 'enabled' : ($state === CapabilityState::Suspended ? 'suspended' : 'disabled');

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', "Capability {$code} {$pastTense} at {$organizationAdmin->facilityName($tenantId, $facilityId)}.");
    }

    private function updateStructureRecord(
        Request $request,
        string $kind,
        string $id,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $config = $this->structureRecordConfig($kind);
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = (string) $request->validate(['facility_id' => ['required', 'uuid']])['facility_id'];
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:64',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique($config['table'], 'code')->where('facility_id', $facilityId)->ignore($id),
            ],
            ...$config['fields'],
        ], [
            'code.unique' => 'This code is already in use at this facility.',
            'code.regex' => 'Use uppercase letters, numbers, hyphens, or underscores.',
        ]);
        $tenantId = $context->tenantId();
        abort_unless(in_array('organization.structure.manage', $scopeChecker->effectivePermissions($actor, $facilityId), true), 403);

        $organizationAdmin->updateStructureRecord(
            $tenantId,
            $facilityId,
            $kind,
            $id,
            $validated,
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', ucfirst($kind)." {$validated['name']} updated.");
    }

    private function setStructureRecordStatus(
        Request $request,
        string $kind,
        string $id,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        OrganizationAdminApi $organizationAdmin,
    ): RedirectResponse {
        $status = $request->validate(['status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]])['status'];
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $tenantId = $context->tenantId();
        $facilityId = (string) $request->validate(['facility_id' => ['required', 'uuid']])['facility_id'];
        abort_unless(in_array('organization.structure.manage', $scopeChecker->effectivePermissions($actor, $facilityId), true), 403);
        $organizationAdmin->setStructureRecordStatus(
            $tenantId,
            $facilityId,
            $kind,
            $id,
            (string) $status,
            (string) $actor->getKey(),
        );

        return redirect()->route('admin.workspace.organization', ['facility_id' => $facilityId])
            ->with('status', ucfirst($kind).($status === 'ACTIVE' ? ' reactivated.' : ' archived.'));
    }

    /** @return array{table: string, fields: array<string, list<string>>} */
    private function structureRecordConfig(string $kind): array
    {
        return match ($kind) {
            'department' => ['table' => 'org_departments', 'fields' => ['kind' => ['nullable', 'string', 'max:100']]],
            'unit' => ['table' => 'org_units', 'fields' => [
                'department_id' => ['required', 'uuid'],
                'unit_type' => ['nullable', 'string', 'max:100'],
            ]],
            'room' => ['table' => 'org_rooms', 'fields' => [
                'unit_id' => ['nullable', 'uuid'],
                'room_type' => ['nullable', 'string', 'max:100'],
            ]],
            default => abort(404),
        };
    }
}
