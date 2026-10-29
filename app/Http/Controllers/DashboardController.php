<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Billing\Application\Query\GetFacilityCashSessions;
use App\Domains\Billing\Application\Query\GetPendingBillableEvents;
use App\Domains\Encounter\Application\Query\CountFacilityEncounters;
use App\Domains\Encounter\Domain\Enum\EncounterStatus;
use App\Domains\HR\Application\FacilityStaffDirectoryService;
use App\Domains\Pharmacy\Application\Query\SearchPharmacyOrders;
use App\Domains\Pharmacy\Application\Query\SearchPrescriptions;
use App\Domains\Pharmacy\Domain\ValueObject\PharmacyOrderStatus;
use App\Domains\Pharmacy\Domain\ValueObject\PrescriptionStatus;
use App\Domains\Scheduling\Contracts\SchedulingApi;
use App\Domains\Scheduling\Domain\ValueObject\AppointmentStatus;
use App\Models\User;
use App\Platform\Queueing\Domain\Enum\QueueStatus;
use App\Platform\Queueing\Domain\Enum\ServiceCapability;
use App\Platform\Queueing\Domain\Model\QueueEntry;
use App\Shared\Authorization\OrganizationPermissionScope;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\QueryBus;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController
{
    public function __invoke(
        Request $request,
        TenantContext $context,
        QueryBus $queryBus,
        SchedulingApi $schedulingApi,
        ScopeChecker $scopeChecker,
        OrganizationPermissionScope $organizationPermissions,
        FacilityStaffDirectoryService $staffDirectory,
    ): Response|RedirectResponse {
        // Post-login landing: render the Dashboard shell with today's
        // counts for the workspaces the role may open (via auth.can), so no
        // role ever lands on an unbuilt page. Workspace deep-links remain
        // permission-gated server-side. Users with no workspace access (or
        // no active facility) see the empty state — this route never 403s.
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if ($user->isPlatformSuperAdmin()) {
            return redirect()->route('platform-admin.index');
        }

        if ($context->facilityId() === null
            && $organizationPermissions->allows($user, 'admin.workspace.access')) {
            $operationalPermissions = [
                'patient.patients.read',
                'patient.patients.create',
                'sch.access',
                'encounter.encounters.read',
                'encounter.encounters.create',
                'queue.ticket.view',
                'clinical.triage.record',
            ];
            $hasOperationalWorkspace = false;
            foreach ($scopeChecker->selectableFacilities($user) as $facilityId) {
                if (array_intersect($operationalPermissions, $scopeChecker->effectivePermissions($user, $facilityId)) !== []) {
                    $hasOperationalWorkspace = true;
                    break;
                }
            }
            if (! $hasOperationalWorkspace) {
                return redirect()->route('admin.workspace.index');
            }
        }

        $facilityId = $context->facilityId();
        $facilityTimeZone = (string) config('app.facility_timezone', 'Africa/Dar_es_Salaam');
        $today = CarbonImmutable::now($facilityTimeZone)->startOfDay();
        $pendingApprovals = DB::table('idn_admin_role_promotion_requests as request')
            ->leftJoin('org_facilities as facility', function ($join): void {
                $join->on('facility.id', '=', 'request.facility_id')
                    ->on('facility.tenant_id', '=', 'request.tenant_id');
            })
            ->where('request.tenant_id', $user->tenant_id)
            ->where('request.target_user_id', $user->getKey())
            ->where('request.status', 'PENDING')
            ->where('request.action', 'PROMOTE')
            ->orderByDesc('request.created_at')
            ->get(['request.role_key', 'facility.name as facility_name'])
            ->map(static fn (object $request): array => [
                'roleLabel' => FacilityUserRoleCatalog::label((string) $request->role_key) ?? (string) $request->role_key,
                'facilityName' => $request->facility_name === null ? null : (string) $request->facility_name,
            ])
            ->all();

        // Shortcut actions for the landing: deep links the role may use,
        // independent of facility context — target pages gate themselves.
        // Notably covers check-in-only roles, which earn no workspace link.
        $quickActions = [];
        if ($user->can('patient.patients.create')) {
            $quickActions[] = [
                'id' => 'register-patient',
                'title' => 'Register new patient',
                'href' => route('patient.register', $user->can('encounter.encounters.create')
                    ? ['after_registration' => 'FRONT_DESK']
                    : []),
            ];
        }
        if ($user->can('encounter.encounters.create')) {
            $quickActions[] = [
                'id' => 'check-in-walk-in',
                'title' => 'Check in walk-in patient',
                'href' => route('reception.index'),
            ];
        }
        if ($user->can('sch.appointment.book')) {
            $quickActions[] = [
                'id' => 'book-appointment',
                'title' => 'Book appointment',
                'href' => route('appointments.calendar'),
            ];
        }

        $widgets = [];
        $restricted = [];
        $unavailable = [];

        if ($facilityId !== null && $facilityId !== '') {
            if ($user->can('hr.person.manage')
                && $user->can('hr.staff.manage')
                && $user->can('hr.assignment.manage')) {
                $quickActions[] = [
                    'id' => 'hr-add-staff',
                    'title' => 'Add staff member',
                    'href' => route('hr.staff.create'),
                ];
            }

            if ($user->can('hr.staff.read') || $user->can('hr.staff.manage')) {
                $staffPermission = $user->can('hr.staff.read') ? 'hr.staff.read' : 'hr.staff.manage';
                $assignedStaff = $this->widget(
                    $user,
                    'hr-assigned-staff',
                    $staffPermission,
                    $restricted,
                    $unavailable,
                    static fn (): int => $staffDirectory->activeStaffCount(
                        (string) $user->tenant_id,
                        $facilityId,
                        $today->toDateString(),
                    ),
                );
                if ($assignedStaff !== null) {
                    $widgets[] = [
                        'id' => 'hr-assigned-staff',
                        'title' => 'Assigned staff',
                        'count' => $assignedStaff,
                        'href' => route('hr.staff.index'),
                    ];
                }
            }

            $canReviewStaffAssignments = ($user->can('admin.workspace.access') && $user->can('identity.assignments.manage'))
                || ($user->can('hr.staff.manage') && $user->can('hr.assignment.manage'));
            if ($canReviewStaffAssignments) {
                $assignmentPermissions = $user->can('hr.staff.manage')
                    ? ['hr.staff.manage', 'hr.assignment.manage']
                    : ['admin.workspace.access', 'identity.assignments.manage'];
                $pendingStaffAssignments = $this->widget(
                    $user,
                    'hr-pending-assignment-requests',
                    $assignmentPermissions,
                    $restricted,
                    $unavailable,
                    static fn (): int => $staffDirectory->pendingAssignmentRequestCount(
                        (string) $user->tenant_id,
                        $facilityId,
                    ),
                );
                if ($pendingStaffAssignments !== null) {
                    $widgets[] = [
                        'id' => 'hr-pending-assignment-requests',
                        'title' => 'Staff placements to review',
                        'count' => $pendingStaffAssignments,
                        'href' => route('hr.staff.onboarding', ['focus' => 'placements']),
                        'section' => 'work',
                    ];
                }
            }

            $canManageStaffAccess = ($user->can('hr.staff.manage') && $user->can('hr.staff_user_link.manage'))
                || ($user->can('admin.workspace.access') && $user->can('identity.assignments.manage'));
            if ($canManageStaffAccess) {
                $accessPermissions = $user->can('hr.staff.manage')
                    ? ['hr.staff.manage', 'hr.staff_user_link.manage']
                    : ['admin.workspace.access', 'identity.assignments.manage'];
                $pendingAccessRequests = $this->widget(
                    $user,
                    'hr-pending-access-requests',
                    $accessPermissions,
                    $restricted,
                    $unavailable,
                    static fn (): int => count($staffDirectory->pendingFacilityInvitationRoleApprovals(
                        (string) $user->tenant_id,
                        $facilityId,
                        (string) $user->getKey(),
                        array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
                    )),
                );
                if ($pendingAccessRequests !== null) {
                    $widgets[] = [
                        'id' => 'hr-pending-access-requests',
                        'title' => 'Role approvals to review',
                        'count' => $pendingAccessRequests,
                        'href' => route('hr.staff.onboarding', ['focus' => 'access']),
                    ];
                }

                $expiredInvitations = $this->widget(
                    $user,
                    'hr-expired-invitations',
                    $accessPermissions,
                    $restricted,
                    $unavailable,
                    static fn (): int => $staffDirectory->expiredInvitationCount(
                        (string) $user->tenant_id,
                        $facilityId,
                    ),
                );
                if ($expiredInvitations !== null) {
                    $widgets[] = [
                        'id' => 'hr-expired-invitations',
                        'title' => 'Expired invitations',
                        'count' => $expiredInvitations,
                        'href' => route('hr.staff.onboarding', ['focus' => 'invitations']),
                    ];
                }
            }

            $scheduled = $this->widget($user, 'scheduled-today', 'sch.access', $restricted, $unavailable,
                static fn (): int => count(array_filter(
                    $schedulingApi->getDailyFacilityAppointments($facilityId, $today),
                    static fn ($appointment): bool => ! in_array($appointment->status, [
                        AppointmentStatus::CANCELLED->value,
                        AppointmentStatus::RESCHEDULED->value,
                        AppointmentStatus::NO_SHOW->value,
                    ], true),
                )));
            if ($scheduled !== null) {
                $widgets[] = [
                    'id' => 'scheduled-today',
                    'title' => 'Scheduled today',
                    'count' => $scheduled,
                    'href' => route('appointments.calendar'),
                ];
            }

            $arrived = $this->widget($user, 'arrived-today', 'encounter.encounters.read', $restricted, $unavailable,
                static fn (): int => $queryBus->dispatch(new CountFacilityEncounters(
                    facilityId: $facilityId,
                    date: $today,
                )));
            if ($arrived !== null) {
                $widgets[] = [
                    'id' => 'arrived-today',
                    'title' => 'Arrived today',
                    'count' => $arrived,
                    'href' => route('reception.index'),
                ];
            }

            $active = $this->widget($user, 'active-visits', 'encounter.encounters.read', $restricted, $unavailable,
                static fn (): int => $queryBus->dispatch(new CountFacilityEncounters(
                    facilityId: $facilityId,
                    date: $today,
                    statuses: [EncounterStatus::Arrived, EncounterStatus::Active],
                )));
            if ($active !== null) {
                $widgets[] = [
                    'id' => 'active-visits',
                    'title' => 'Active visits',
                    'count' => $active,
                    'href' => route('reception.index'),
                ];
            }

            $triage = $this->widget(
                $user,
                'triage-queue',
                ['queue.ticket.view', 'clinical.access', 'clinical.triage.record', 'patient.patients.read'],
                $restricted,
                $unavailable,
                static fn (): int => QueueEntry::query()
                    ->where('facility_id', $facilityId)
                    ->whereIn('status', [
                        QueueStatus::WAITING->value,
                        QueueStatus::CALLED->value,
                        QueueStatus::IN_SERVICE->value,
                    ])
                    ->whereHas('queue', static fn ($query) => $query
                        ->where('service_capability', ServiceCapability::TRIAGE->value))
                    ->count(),
            );
            if ($triage !== null) {
                $widgets[] = [
                    'id' => 'triage-queue',
                    'title' => 'Triage queue',
                    'count' => $triage,
                    'href' => route('nurse-station.index'),
                    'section' => 'work',
                ];
            }

            $consultations = $this->widget(
                $user,
                'consultation-queue',
                ['queue.ticket.view', 'clinical.access', 'clinical.consultations.read', 'patient.patients.read'],
                $restricted,
                $unavailable,
                static fn (): int => QueueEntry::query()
                    ->where('facility_id', $facilityId)
                    ->whereIn('status', [
                        QueueStatus::WAITING->value,
                        QueueStatus::CALLED->value,
                        QueueStatus::IN_SERVICE->value,
                    ])
                    ->whereHas('queue', static fn ($query) => $query
                        ->where('service_capability', ServiceCapability::CONSULTATION->value))
                    ->count(),
            );
            if ($consultations !== null) {
                $widgets[] = [
                    'id' => 'consultation-queue',
                    'title' => 'Consultation queue',
                    'count' => $consultations,
                    'href' => route('clinician.index'),
                    'section' => 'work',
                ];
            }

            $prescriptions = $this->widget(
                $user,
                'prescriptions-to-review',
                ['pharmacy.access', 'pharmacy.prescriptions.accept'],
                $restricted,
                $unavailable,
                static fn (): int => count($queryBus->dispatch(new SearchPrescriptions(
                    $facilityId,
                    PrescriptionStatus::RECEIVED,
                ))),
            );
            if ($prescriptions !== null) {
                $widgets[] = [
                    'id' => 'prescriptions-to-review',
                    'title' => 'Prescriptions to review',
                    'count' => $prescriptions,
                    'href' => route('pharmacy.index'),
                    'section' => 'work',
                ];
            }

            $pharmacyOrders = $this->widget(
                $user,
                'pharmacy-orders-to-process',
                ['pharmacy.access', 'pharmacy.dispensing.record'],
                $restricted,
                $unavailable,
                static function () use ($facilityId, $queryBus): int {
                    $orders = $queryBus->dispatch(new SearchPharmacyOrders($facilityId));

                    return count(array_filter(
                        $orders,
                        static fn ($order): bool => in_array($order->status, [
                            PharmacyOrderStatus::RECEIVED,
                            PharmacyOrderStatus::VALIDATED,
                            PharmacyOrderStatus::PARTIALLY_DISPENSED,
                        ], true),
                    ));
                },
            );
            if ($pharmacyOrders !== null) {
                $widgets[] = [
                    'id' => 'pharmacy-orders-to-process',
                    'title' => 'Pharmacy orders to process',
                    'count' => $pharmacyOrders,
                    'href' => route('pharmacy.index'),
                    'section' => 'work',
                ];
            }

            $cashSession = $this->widget(
                $user,
                'my-cash-session',
                ['billing.access', 'billing.cash_sessions.manage'],
                $restricted,
                $unavailable,
                static function () use ($facilityId, $queryBus, $user): int {
                    $sessions = $queryBus->dispatch(new GetFacilityCashSessions($facilityId));

                    foreach ($sessions as $session) {
                        if ($session->cashierUserId === (string) $user->getAuthIdentifier()
                            && in_array($session->status, ['OPEN', 'ACTIVE'], true)) {
                            return 1;
                        }
                    }

                    return 0;
                },
            );
            if ($cashSession !== null) {
                $widgets[] = [
                    'id' => 'my-cash-session',
                    'title' => 'My active cash session',
                    'count' => $cashSession,
                    'href' => route('billing.index'),
                    'section' => 'work',
                ];
            }

            $pendingBillableEvents = $this->widget(
                $user,
                'pending-billing-events',
                ['billing.access', 'billing.charges.manage'],
                $restricted,
                $unavailable,
                static fn (): int => count($queryBus->dispatch(new GetPendingBillableEvents($facilityId))),
            );
            if ($pendingBillableEvents !== null) {
                $widgets[] = [
                    'id' => 'pending-billing-events',
                    'title' => 'Charges needing review',
                    'count' => $pendingBillableEvents,
                    'href' => route('billing.catalog.index'),
                    'section' => 'work',
                ];
            }
        }

        return Inertia::render('Dashboard/Index', [
            'date' => $today->toDateString(),
            'quickActions' => $quickActions,
            'widgets' => $widgets,
            'restricted' => $restricted,
            'unavailable' => $unavailable,
            'pendingApprovals' => $pendingApprovals,
        ]);
    }

    /**
     * Permission-gated count widget: denied callers land in `restricted`,
     * failed reads in `unavailable`, and only successful reads render —
     * the dashboard never leaks counts the role may not see and never 500s.
     *
     * @param  string|list<string>  $permission
     * @param  callable(): int  $read
     * @param  list<string>  $restricted
     * @param  list<string>  $unavailable
     */
    private function widget(
        User $user,
        string $id,
        string|array $permission,
        array &$restricted,
        array &$unavailable,
        callable $read,
    ): ?int {
        $permissions = is_array($permission) ? $permission : [$permission];
        foreach ($permissions as $requiredPermission) {
            if ($user->can($requiredPermission)) {
                continue;
            }

            $restricted[] = $id;

            return null;
        }

        try {
            return $read();
        } catch (\Throwable) {
            $unavailable[] = $id;

            return null;
        }
    }
}
