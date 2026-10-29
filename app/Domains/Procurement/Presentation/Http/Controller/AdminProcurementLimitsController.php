<?php

declare(strict_types=1);

namespace App\Domains\Procurement\Presentation\Http\Controller;

use App\Domains\Organization\Contracts\OrganizationApi;
use App\Domains\Procurement\Application\ApprovalLimitResolver;
use App\Domains\Procurement\Application\Command\SetApprovalLimit;
use App\Domains\Procurement\Domain\ApprovalPolicyRepository;
use App\Models\User;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Procurement limits (Shape B; 19-procurement-domain.md §45, §228).
 *
 * The screen lists ONLY the facilities the signed-in user may actually set a
 * limit for — a Tenant Admin gets every facility in the tenant, a Facility
 * Admin only the facilities they are assigned to. That is decided per facility
 * (`ScopeChecker::effectivePermissions`), never by a role name, and the write
 * path re-checks the same thing through the command bus policy.
 *
 * The working context narrows the view further: in General context every
 * manageable facility is listed, while a facility context shows just the
 * active facility — switching facility switches the page with it.
 *
 * The controller orchestrates: it reads through the repository + the
 * Organization port and delegates every write to `SetApprovalLimit`.
 */
final class AdminProcurementLimitsController
{
    private const PERMISSION = 'procurement.approval_limits.manage';

    public function index(
        Request $request,
        ScopeChecker $scope,
        ApprovalPolicyRepository $policies,
        ApprovalLimitResolver $limits,
        OrganizationApi $organization,
        TenantContext $context,
    ): Response {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $facilityIds = $scope->selectableFacilities($user);

        // A facility context narrows the screen to that facility only; General
        // keeps every facility the user may manage. The selection is still
        // checked per facility below — context scopes the view, never
        // authorization (docs/00-foundation/04-authentication.md §31).
        if (! $context->isGeneral()) {
            $activeFacilityId = $context->requireFacilityId();
            $facilityIds = in_array($activeFacilityId, $facilityIds, true)
                ? [$activeFacilityId]
                : [];
        }

        $facilities = [];

        foreach ($facilityIds as $facilityId) {
            if (! in_array(self::PERMISSION, $scope->effectivePermissions($user, $facilityId), true)) {
                continue;
            }

            $structure = $organization->getFacilityStructure($facilityId);

            if ($structure === null) {
                continue;
            }

            $facilityLimit = $policies->findFor($facilityId, null);
            $departmentLimits = [];

            foreach ($policies->listForFacility($facilityId) as $row) {
                if ($row->department_ref !== null) {
                    $departmentLimits[(string) $row->department_ref] = (string) $row->self_approval_limit;
                }
            }

            $facilities[] = [
                'id' => $facilityId,
                'name' => $structure->facilityName,
                'type' => $organization->facilityType($facilityId),
                'limit' => $facilityLimit !== null ? (string) $facilityLimit->self_approval_limit : null,
                'effectiveLimit' => $limits->resolve($facilityId, null),
                'departments' => array_map(
                    fn (array $department): array => [
                        'id' => (string) $department['id'],
                        'name' => (string) $department['name'],
                        'code' => (string) $department['code'],
                        'limit' => $departmentLimits[(string) $department['id']] ?? null,
                        'effectiveLimit' => $limits->resolve($facilityId, (string) $department['id']),
                    ],
                    $structure->departments,
                ),
            ];
        }

        abort_if($facilities === [], 403, 'You are not authorized to manage procurement approval limits.');

        return Inertia::render('Admin/ProcurementLimits/Index', [
            'facilities' => $facilities,
            'typeDefaults' => (array) config('procurement.approval.facility_type_defaults', []),
        ]);
    }

    public function store(Request $request, ScopeChecker $scope, OrganizationApi $organization): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'facility_id' => ['required', 'uuid'],
            'department_id' => ['nullable', 'uuid'],
            'limit' => ['nullable', 'numeric', 'min:0', 'max:999999999999999'],
            'clear' => ['nullable', 'boolean'],
        ]);

        $facilityId = (string) $validated['facility_id'];

        abort_unless(
            in_array(self::PERMISSION, $scope->effectivePermissions($user, $facilityId), true),
            403,
            'You are not authorized to manage this facility\'s approval limit.',
        );

        // `ConvertEmptyStringsToNull` turns the "whole facility" select value
        // into null, and `isset()` is false for a null array value — so an
        // absent key and an empty scope both land on the facility row.
        $departmentId = isset($validated['department_id'])
            ? (string) $validated['department_id']
            : null;

        if ($departmentId !== null && ! $organization->departmentExists($facilityId, $departmentId)) {
            throw ValidationException::withMessages([
                'department_id' => 'That department does not belong to this facility.',
            ]);
        }

        $clear = (bool) ($validated['clear'] ?? false);

        if (! $clear && trim((string) ($validated['limit'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'limit' => 'Enter an amount, or use Remove to fall back to the wider limit.',
            ]);
        }

        app(CommandBus::class)->dispatch(new SetApprovalLimit(
            facilityId: $facilityId,
            departmentRef: $departmentId,
            limit: $clear ? null : (string) $validated['limit'],
            actorId: (string) $user->id,
        ));

        return back()->with('status', $clear
            ? 'Approval limit removed — the wider limit applies again.'
            : 'Approval limit saved.');
    }
}
