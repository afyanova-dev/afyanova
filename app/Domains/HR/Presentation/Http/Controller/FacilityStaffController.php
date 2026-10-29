<?php

declare(strict_types=1);

namespace App\Domains\HR\Presentation\Http\Controller;

use App\Domains\Authentication\Application\PlatformAdministrationService;
use App\Domains\Authentication\Contracts\AccountDirectoryApi;
use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\HR\Application\Command\CreateEmployment;
use App\Domains\HR\Application\Command\CreatePerson;
use App\Domains\HR\Application\Command\CreateStaff;
use App\Domains\HR\Application\Command\DefineCadre;
use App\Domains\HR\Application\Command\DefinePosition;
use App\Domains\HR\Application\Command\LinkStaffToUser;
use App\Domains\HR\Application\Command\StartEmployment;
use App\Domains\HR\Application\Command\UnlinkStaffFromUser;
use App\Domains\HR\Application\Command\UpdatePerson;
use App\Domains\HR\Application\FacilityAssignmentRequestService;
use App\Domains\HR\Application\FacilityStaffDirectoryService;
use App\Domains\HR\Application\FacilityStaffLifecycleService;
use App\Domains\HR\Application\FacilityStaffOnboardingService;
use App\Domains\HR\Domain\Exception\InvalidStaffStatusTransitionException;
use App\Domains\HR\Domain\Exception\StaffHasActiveAssignmentsException;
use App\Domains\HR\Domain\Exception\StaffHasActiveEmploymentException;
use App\Domains\HR\Domain\Exception\StaffHasFutureRosterEntriesException;
use App\Domains\HR\Domain\ValueObject\AssignmentScope;
use App\Domains\HR\Domain\ValueObject\CadreCategory;
use App\Domains\Identity\Application\LinkedStaffAccessService;
use App\Domains\Identity\Contracts\FacilityStaffManagerRoleDefinition;
use App\Domains\Identity\Contracts\IdentityApi;
use App\Domains\Organization\Contracts\OrganizationAdminApi;
use App\Domains\Organization\Contracts\OrganizationApi;
use App\Models\User;
use App\Platform\Documents\Contracts\DocumentsApi;
use App\Platform\Documents\Contracts\DTO\UploadDocumentDto;
use App\Platform\Documents\Domain\Enum\ConfidentialityLevel;
use App\Platform\Documents\Domain\Enum\DocumentType;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\ScopeChecker;
use App\Shared\Bus\CommandBus;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class FacilityStaffController
{
    public function index(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        IdentityApi $identity,
        FacilityStaffDirectoryService $directory,
        OrganizationAdminApi $organizationAdminApi,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            $facilities = $this->selectableHRFacilities($actor, $context, $scopeChecker);
            abort_unless($facilities !== [], 403);

            return Inertia::render('HR/Staff/SelectFacility', ['facilities' => $facilities]);
        }
        $facilityId = $this->activeFacilityId($context);
        if (in_array(
            FacilityStaffManagerRoleDefinition::KEY,
            $scopeChecker->effectiveRoles($actor, $facilityId),
            true,
        )) {
            $identity->provisionFacilityStaffManagerRole(
                tenantId: $context->tenantId(),
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
            );
        }
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canReadStaff = $this->hasAny($permissions, ['hr.staff.read', 'hr.staff.manage']);
        $canReviewAssignments = $this->canReviewAssignments($permissions);
        abort_unless($canReadStaff || $canReviewAssignments, 403);
        if ($this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);

        $staffStatus = strtoupper(trim((string) $request->query('status', 'ALL')));
        $allowedStatuses = ['ALL', 'ACTIVE', 'PROSPECT', 'ONBOARDING', 'SUSPENDED', 'TERMINATED'];
        if (! in_array($staffStatus, $allowedStatuses, true)) {
            $staffStatus = 'ALL';
        }
        $statusCounts = $directory->staffStatusCounts(
            $context->tenantId(),
            $facilityId,
            now()->toDateString(),
        );
        if ($staffStatus !== 'ALL' && ($statusCounts[$staffStatus] ?? 0) === 0) {
            $staffStatus = 'ALL';
        }
        $search = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $directoryPage = $directory->staffDirectory(
            $context->tenantId(),
            $facilityId,
            now()->toDateString(),
            $staffStatus,
            $search,
            $page,
        );

        return Inertia::render('HR/Staff/Index', [
            'facility' => ['id' => $facility['id'], 'name' => $facility['name']],
            'staff' => $directoryPage->items(),
            'pagination' => [
                'page' => $directoryPage->currentPage(),
                'perPage' => $directoryPage->perPage(),
                'total' => $directoryPage->total(),
                'lastPage' => $directoryPage->lastPage(),
            ],
            'statusCounts' => $statusCounts,
            'activeStatus' => $staffStatus,
            'search' => $search,
        ]);
    }

    public function onboarding(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffOnboardingService $onboarding,
        OrganizationAdminApi $organizationAdminApi,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }

        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canReadStaff = $this->hasAny($permissions, ['hr.staff.read', 'hr.staff.manage']);
        $canReviewPlacements = $this->canReviewAssignments($permissions);
        $canManagePlacement = $this->hasAll($permissions, [
            'hr.person.manage',
            'hr.staff.manage',
            'hr.assignment.manage',
        ]);
        $canManageStaff = in_array('hr.staff.manage', $permissions, true);
        $canManageEmployment = in_array('hr.employment.manage', $permissions, true);
        $canManageAccess = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        ) || $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        abort_unless($canReadStaff || $canReviewPlacements || $canManageAccess, 403);
        if ($this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);

        $focus = (string) $request->query('focus', 'needs-action');
        if (! in_array($focus, [
            'needs-action',
            'in-progress',
            'all',
            'placements',
            'access',
            'invitations',
            'onboarding',
            'ready',
        ], true)) {
            $focus = 'needs-action';
        }

        $worklist = $onboarding->worklist(
            tenantId: $context->tenantId(),
            facilityId: $facilityId,
            today: now()->toDateString(),
            actorId: (string) $actor->getKey(),
            roleKeys: array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
            canReviewPlacements: $canReviewPlacements,
            canManagePlacement: $canManagePlacement,
            canManageStaff: $canManageStaff,
            canManageEmployment: $canManageEmployment,
            canManageAccess: $canManageAccess,
        );

        return Inertia::render('HR/Staff/Onboarding', [
            'facility' => ['id' => $facility['id'], 'name' => $facility['name']],
            'rows' => $worklist['rows'],
            'summary' => $worklist['summary'],
            'focus' => $focus,
            'permissions' => [
                'canReviewPlacements' => $canReviewPlacements,
                'canManageStaff' => $canManageStaff,
                'canManageAccess' => $canManageAccess,
            ],
        ]);
    }

    public function accountInvitations(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
        OrganizationAdminApi $organizationAdminApi,
        PlatformAdministrationService $administration,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }

        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canResendInvitations = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        ) || $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        abort_unless($canResendInvitations, 403);
        if ($this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);

        return Inertia::render('HR/Staff/AccountInvitations', [
            'facility' => ['id' => $facility['id'], 'name' => $facility['name']],
            'invitations' => $administration->facilityInvitationsForWorklist(
                $context->tenantId(),
                $facilityId,
            ),
            'canResendInvitations' => true,
            'canRevokeInvitations' => true,
            'canReviewAccessRequests' => true,
            'pendingAccessRequestCount' => count($directory->pendingFacilityInvitationRoleApprovals(
                $context->tenantId(),
                $facilityId,
                (string) $actor->getKey(),
                array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
            )),
        ]);
    }

    public function accessRequests(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
        OrganizationAdminApi $organizationAdminApi,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }

        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canReviewAccessRequests = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        ) || $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        abort_unless($canReviewAccessRequests, 403);
        if ($this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);
        $pendingRequests = $directory->pendingFacilityInvitationRoleApprovals(
            $context->tenantId(),
            $facilityId,
            (string) $actor->getKey(),
            array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
        );
        $roleLabels = array_map(
            static fn (array $accessRequest): array => [
                ...$accessRequest,
                'roleLabel' => FacilityUserRoleCatalog::label($accessRequest['roleKey'])
                    ?? $accessRequest['roleKey'],
            ],
            $pendingRequests,
        );

        return Inertia::render('HR/Staff/AccessRequests', [
            'facility' => ['id' => $facility['id'], 'name' => $facility['name']],
            'timezone' => (string) config('app.facility_timezone', 'Africa/Dar_es_Salaam'),
            'requests' => $roleLabels,
            'pendingAccessRequestCount' => count($roleLabels),
            'canReviewAccessRequests' => true,
        ]);
    }

    public function create(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageStaff = $this->hasAll($permissions, ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage']);
        abort_unless($canManageStaff, 403);
        $canInviteUser = $this->hasAll($permissions, ['hr.staff.manage', 'hr.staff_user_link.manage']);

        return Inertia::render('HR/Staff/Create', [
            'facility' => $this->facilitySummary($context, $facilityId),
            'departments' => $this->departments($context, $facilityId),
            'units' => $this->units($context, $facilityId),
            'positions' => in_array('hr.employment.manage', $permissions, true)
                ? $this->positionsList($context)
                : [],
            'canManageEmployment' => in_array('hr.employment.manage', $permissions, true),
            'canManagePositions' => in_array('hr.cadre_position.manage', $permissions, true),
            'canInviteUser' => $canInviteUser,
            'invitationRoles' => $canInviteUser ? $this->facilityInvitationRoles($context, $facilityId) : [],
        ]);
    }

    public function show(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        IdentityApi $identity,
        OrganizationAdminApi $organizationAdminApi,
        FacilityStaffDirectoryService $directory,
        PlatformAdministrationService $administration,
        string $staff,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canReviewAssignments = $this->canReviewAssignments($permissions);
        abort_unless(
            $this->hasAny($permissions, ['hr.staff.read', 'hr.staff.manage']) || $canReviewAssignments,
            403,
        );
        $canManagePerson = in_array('hr.person.manage', $permissions, true);
        $canManageEmployment = in_array('hr.employment.manage', $permissions, true);
        $canManageStaff = in_array('hr.staff.manage', $permissions, true);
        $canReadQualifications = $this->hasAny($permissions, ['hr.staff.read', 'hr.staff.manage']);
        $canLinkStaff = in_array('hr.staff_user_link.manage', $permissions, true);
        $canManageIdentityAssignments = $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        $mayInviteUser = $canManageIdentityAssignments
            || ($canManageStaff && $canLinkStaff);
        $today = now()->toDateString();

        $rowData = $directory->staffProfile($context->tenantId(), $facilityId, $staff, $today);
        $row = $rowData === null ? null : (object) $rowData;
        abort_unless($row !== null, 404);

        $hasCurrentFacilityAssignment = $directory->hasCurrentFacilityAssignment(
            $context->tenantId(),
            $facilityId,
            $staff,
            $today,
        );
        $canManagePerson = $canManagePerson && $hasCurrentFacilityAssignment;
        $canManageAccountLink = $hasCurrentFacilityAssignment
            && in_array((string) $row->staff_status, ['PROSPECT', 'ACTIVE'], true)
            && $row->linked_user_id === null;
        $canLinkStaff = $canLinkStaff && $canManageAccountLink;
        $canInviteUser = $mayInviteUser && $canManageAccountLink;
        $pendingSetupInvitation = $row->linked_user_id === null
            ? null
            : $administration->pendingFacilityInvitationForAccount(
                $context->tenantId(),
                $facilityId,
                (string) $row->linked_user_id,
            );
        $setupInvitationExpired = $pendingSetupInvitation !== null
            && $pendingSetupInvitation->emailVerifiedAt === null
            && $pendingSetupInvitation->isExpired();
        $canResendInvitation = $mayInviteUser
            && $hasCurrentFacilityAssignment
            && in_array((string) $row->staff_status, ['PROSPECT', 'ACTIVE'], true)
            && $pendingSetupInvitation !== null
            && $pendingSetupInvitation->canResend();
        $canRevokeInvitation = $mayInviteUser
            && $hasCurrentFacilityAssignment
            && in_array((string) $row->staff_status, ['PROSPECT', 'ACTIVE'], true)
            && $pendingSetupInvitation !== null
            && $pendingSetupInvitation->canRevoke();
        $canManageStaffAccess = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        );
        $canUnlinkAccount = $canManageStaffAccess
            && $hasCurrentFacilityAssignment
            && $pendingSetupInvitation !== null
            && $pendingSetupInvitation->emailVerifiedAt === null
            && $pendingSetupInvitation->consumedAt === null;
        $pendingInvitationAccessRequests = $row->linked_user_id === null
            ? []
            : array_map(
                static fn (array $pendingRequest): array => [
                    ...$pendingRequest,
                    'roleLabel' => FacilityUserRoleCatalog::label($pendingRequest['roleKey'])
                        ?? $pendingRequest['roleKey'],
                ],
                $directory->pendingInvitationAccessRequests(
                    $context->tenantId(),
                    $facilityId,
                    (string) $row->linked_user_id,
                    (string) $actor->getKey(),
                    array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
                ),
            );
        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);
        $staffAccessAssignments = $row->linked_user_id === null
            ? []
            : $identity->facilityAccountAssignments(
                $context->tenantId(),
                $facilityId,
                $facility['organizationId'],
                (string) $row->linked_user_id,
            );
        $canReadAccessHistory = $canManageStaffAccess
            || in_array('identity.assignments.read', $permissions, true);
        $staffAccessHistory = $row->linked_user_id === null || ! $canReadAccessHistory
            ? []
            : $identity->facilityAccountAccessHistory(
                $context->tenantId(),
                $facilityId,
                $facility['organizationId'],
                (string) $row->linked_user_id,
            );
        $staffInvitationHistory = $row->linked_user_id === null || ! $mayInviteUser
            ? []
            : $administration->invitationHistoryForStaff(
                $context->tenantId(),
                $facilityId,
                $staff,
                (string) $row->linked_user_id,
            );

        $employment = $canManageEmployment
            ? $directory->latestEmployment($context->tenantId(), $staff)
            : null;
        $employment = $employment === null ? null : (object) $employment;
        $terminationBlockers = $canManageStaff
            ? $directory->terminationBlockers($context->tenantId(), $staff, $today)
            : [
                'activeAssignments' => 0,
                'openEmployments' => 0,
                'futurePublishedShifts' => 0,
            ];

        return Inertia::render('HR/Staff/Show', [
            'facility' => $this->facilitySummary($context, $facilityId),
            'qualifications' => $canReadQualifications ? DB::table('hr_qualifications')
                ->where('tenant_id', $context->tenantId())
                ->where('staff_id', $staff)
                ->orderByDesc('completion_year')
                ->orderBy('award')
                ->get([
                    'id',
                    'award',
                    'field_of_study',
                    'institution',
                    'completion_year',
                    'verification_status',
                ])
                ->map(static fn (object $qualification): array => [
                    'id' => (string) $qualification->id,
                    'award' => (string) $qualification->award,
                    'fieldOfStudy' => (string) $qualification->field_of_study,
                    'institution' => (string) $qualification->institution,
                    'completionYear' => $qualification->completion_year === null
                        ? null
                        : (int) $qualification->completion_year,
                    'verificationStatus' => (string) $qualification->verification_status,
                ])
                ->all() : [],
            'canReadQualifications' => $canReadQualifications,
            'staff' => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
                'status' => (string) $row->staff_status,
                'joinedDate' => $row->joined_date,
                'suspensionReason' => $row->suspension_reason,
                'terminatedDate' => $row->terminated_date,
                'terminationReason' => $row->termination_reason,
                'positionTitle' => $row->position_title,
                'departmentName' => $row->department_name,
                'unitName' => $row->unit_name,
                'assignmentScope' => (string) $row->assignment_scope,
                'hasCurrentFacilityAssignment' => $hasCurrentFacilityAssignment,
                'pendingAssignmentRequestId' => $row->pending_assignment_request_id === null
                    ? null
                    : (string) $row->pending_assignment_request_id,
                'pendingAssignmentEffectiveFrom' => $row->pending_assignment_effective_from === null
                    ? null
                    : (string) $row->pending_assignment_effective_from,
                'phone' => $row->phone,
                'email' => $row->email,
                'linkedUserId' => $row->linked_user_id === null ? null : (string) $row->linked_user_id,
                'linkedUserEmail' => $row->linked_user_email,
                'setupInvitation' => $pendingSetupInvitation === null ? null : [
                    'id' => $pendingSetupInvitation->invitationId,
                    'status' => $pendingSetupInvitation->invitationStatus,
                    'sentAt' => $pendingSetupInvitation->sentAt,
                    'expiresAt' => $pendingSetupInvitation->expiresAt,
                    'expired' => $setupInvitationExpired,
                    'canResend' => $canResendInvitation,
                    'canRevoke' => $canRevokeInvitation,
                    'deliveryStatus' => $pendingSetupInvitation->deliveryStatus,
                    'lastSentByName' => $pendingSetupInvitation->lastSentByName,
                    'roles' => $this->invitationRoleKeys($pendingSetupInvitation->roleKeys, $pendingSetupInvitation->roleKey),
                ],
                'personProfile' => $canManagePerson ? [
                    'firstName' => (string) $row->first_name,
                    'middleName' => $row->middle_name,
                    'lastName' => (string) $row->last_name,
                    'dateOfBirth' => (string) $row->date_of_birth,
                    'gender' => (string) $row->gender,
                    'nationalId' => $row->national_id,
                    'phone' => $row->phone,
                    'email' => $row->email,
                    'physicalAddress' => $row->physical_address,
                    'photoDocumentId' => $row->photo_document_id === null ? null : (string) $row->photo_document_id,
                ] : null,
                'photoUrl' => $row->photo_document_id === null
                    ? null
                    : route('hr.staff.photo', ['staff' => $row->id]),
                'employment' => $employment === null ? null : [
                    'id' => (string) $employment->id,
                    'contractType' => (string) $employment->contract_type,
                    'status' => (string) $employment->status,
                    'startDate' => $employment->start_date,
                    'fte' => (string) $employment->fte,
                    'endDate' => $employment->end_date,
                    'probationEndDate' => $employment->probation_end_date,
                    'terms' => $employment->terms_summary,
                ],
            ],
            'terminationBlockers' => $terminationBlockers,
            'canManagePerson' => $canManagePerson,
            'canManageEmployment' => $canManageEmployment,
            'canManageStaff' => $canManageStaff,
            'canCreateStaff' => $this->hasAll($permissions, ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage']),
            'canLinkStaff' => $canLinkStaff,
            'canInviteUser' => $canInviteUser,
            'canResendInvitation' => $canResendInvitation,
            'canRevokeInvitation' => $canRevokeInvitation,
            'canUnlinkAccount' => $canUnlinkAccount,
            'invitationRoles' => $canInviteUser ? $this->facilityInvitationRoles($context, $facilityId) : [],
            'pendingInvitationAccessRequests' => $pendingInvitationAccessRequests,
            'invitationHistory' => $staffInvitationHistory,
            'accessAssignments' => $staffAccessAssignments,
            'accessHistory' => $staffAccessHistory,
            'canManageStaffAccess' => $canManageStaffAccess,
            'canReadAccessHistory' => $canReadAccessHistory,
            'canManagePositions' => in_array('hr.cadre_position.manage', $permissions, true),
            'canReviewAssignments' => $canReviewAssignments,
        ]);
    }

    public function manageAccessAssignment(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        LinkedStaffAccessService $access,
        AuditLogger $audit,
        string $staff,
        string $assignment,
        string $action,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        abort_unless(in_array($action, ['suspend', 'restore', 'revoke'], true), 404);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions(
            $actor,
            $facilityId,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
            $scopeChecker,
        );
        $reason = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:1000'],
        ])['reason'];

        DB::transaction(function () use (
            $context,
            $staff,
            $assignment,
            $facilityId,
            $action,
            $reason,
            $actor,
            $access,
            $audit,
        ): void {
            $target = DB::table('hr_staff_user_links as link')
                ->join('idn_access_assignments as assignment', function ($join): void {
                    $join->on('assignment.user_id', '=', 'link.user_id')
                        ->on('assignment.tenant_id', '=', 'link.tenant_id');
                })
                ->join('idn_roles as role', function ($join): void {
                    $join->on('role.id', '=', 'assignment.role_id')
                        ->on('role.tenant_id', '=', 'assignment.tenant_id');
                })
                ->where('link.tenant_id', $context->tenantId())
                ->where('link.staff_id', $staff)
                ->where('assignment.id', $assignment)
                ->where('assignment.facility_id', $facilityId)
                ->lockForUpdate()
                ->first(['link.user_id', 'role.key as role_key']);
            abort_unless($target !== null, 404);
            abort_unless(array_key_exists((string) $target->role_key, FacilityUserRoleCatalog::STAFF_ROLES), 422);

            if ($action === 'suspend') {
                $access->suspendAssignment(
                    $context->tenantId(),
                    $assignment,
                    (string) $actor->getKey(),
                    trim($reason),
                    $audit,
                    $facilityId,
                );
            } elseif ($action === 'restore') {
                $access->restoreAssignment(
                    $context->tenantId(),
                    $assignment,
                    (string) $actor->getKey(),
                    trim($reason),
                    $audit,
                    $facilityId,
                );
            } else {
                $access->revokeAssignment(
                    $context->tenantId(),
                    $assignment,
                    (string) $actor->getKey(),
                    trim($reason),
                    $audit,
                    $facilityId,
                );
            }
        });

        return redirect()->route('hr.staff.show', ['staff' => $staff])
            ->with('status', 'Staff access updated.');
    }

    public function assignmentRequests(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityStaffDirectoryService $directory,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canReviewAssignments = $this->canReviewAssignments($permissions);
        $canManageStaff = $this->hasAll($permissions, ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage']);
        abort_unless($canReviewAssignments || $canManageStaff, 403);
        if ($this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $pending = $canReviewAssignments
            ? $directory->pendingAssignmentRequests($context->tenantId(), $facilityId)
            : [];

        return Inertia::render('HR/Staff/AssignmentRequests', [
            'facility' => $this->facilitySummary($context, $facilityId),
            'pendingRequests' => $pending,
            'rejectedRequests' => $canManageStaff
                ? $directory->rejectedAssignmentRequests(
                    $context->tenantId(),
                    $facilityId,
                    (string) $actor->getKey(),
                )
                : [],
            'departments' => $this->departments($context, $facilityId),
            'units' => $this->units($context, $facilityId),
            'canReviewAssignments' => $canReviewAssignments,
            'canManageStaff' => $canManageStaff,
            'canManagePositions' => in_array('hr.cadre_position.manage', $permissions, true),
        ]);
    }

    public function positions(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
    ): Response|RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        if ($context->facilityId() === null) {
            return redirect()->route('hr.staff.index');
        }
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.cadre_position.manage'], $scopeChecker);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);

        return Inertia::render('HR/Staff/Positions', [
            'facility' => $this->facilitySummary($context, $facilityId),
            'cadres' => $this->cadresList($context),
            'positions' => $this->positionsList($context),
            'canManageStaff' => $this->hasAll($permissions, ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage']),
        ]);
    }

    public function store(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        AuditLogger $audit,
        PlatformAdministrationService $administration,
        DocumentsApi $documents,
        FacilityAssignmentRequestService $assignmentRequests,
        OrganizationAdminApi $organization,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions(
            $actor,
            $facilityId,
            ['hr.person.manage', 'hr.staff.manage', 'hr.assignment.manage'],
            $scopeChecker,
        );
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'])],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['required_if:request_login,true', 'nullable', 'email', 'max:150'],
            'request_login' => ['sometimes', 'boolean'],
            'primary_role_key' => [
                'required_if:request_login,true',
                'nullable',
                Rule::in(array_keys(FacilityUserRoleCatalog::STAFF_ROLES)),
            ],
            'secondary_role_keys' => ['sometimes', 'array', 'max:2'],
            'secondary_role_keys.*' => [
                'string',
                'distinct',
                Rule::in(array_keys(FacilityUserRoleCatalog::STAFF_ROLES)),
                Rule::notIn([(string) $request->input('primary_role_key', '')]),
            ],
            'change_reference' => ['required_if:request_login,true', 'nullable', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'access_reason' => ['required_if:request_login,true', 'nullable', 'string', 'min:8', 'max:500'],
            'department_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'effective_from' => ['required', 'date'],
            'position_id' => ['nullable', 'uuid'],
            'contract_type' => ['nullable', Rule::in(['PERMANENT', 'FIXED_TERM', 'CASUAL', 'LOCUM', 'VOLUNTEER', 'INTERN'])],
            'employment_start_date' => ['nullable', 'date'],
            'fte' => ['nullable', 'numeric', 'between:0.01,9.99'],
        ]);
        $positionId = $validated['position_id'] ?? null;
        $contractType = $validated['contract_type'] ?? null;
        $employmentStartDate = $validated['employment_start_date'] ?? null;
        if ($positionId === null && ($contractType !== null || $employmentStartDate !== null)) {
            throw ValidationException::withMessages([
                'position_id' => 'Select a position before adding employment details.',
            ]);
        }
        if ($positionId !== null && $contractType === null) {
            throw ValidationException::withMessages([
                'contract_type' => 'Select a contract type for the selected position.',
            ]);
        }
        if ($positionId !== null && $employmentStartDate === null) {
            throw ValidationException::withMessages([
                'employment_start_date' => 'Enter an employment start date for the selected position.',
            ]);
        }
        $position = null;
        if ($positionId !== null) {
            $position = app(FacilityStaffDirectoryService::class)->activePosition(
                $context->tenantId(),
                $positionId,
            );
            if ($position === null) {
                throw ValidationException::withMessages([
                    'position_id' => 'The selected position is not active.',
                ]);
            }
        }

        $departmentId = $validated['department_id'] ?? null;
        $unitId = $validated['unit_id'] ?? null;
        if ($departmentId !== null) {
            if (! $organization->activeDepartmentInFacility(
                $context->tenantId(),
                $facilityId,
                $departmentId,
            )) {
                throw ValidationException::withMessages([
                    'department_id' => 'The selected department is not active in this facility.',
                ]);
            }
        }
        if ($unitId !== null) {
            if ($departmentId === null || ! $organization->activeUnitInDepartment(
                $context->tenantId(),
                $facilityId,
                $departmentId,
                $unitId,
            )) {
                throw ValidationException::withMessages([
                    'unit_id' => 'The selected unit must be active in the selected department.',
                ]);
            }
        }

        $personId = (string) Str::uuid7();
        $staffId = (string) Str::uuid7();
        $requestId = (string) Str::uuid7();
        $scope = $unitId !== null
            ? AssignmentScope::UNIT
            : ($departmentId !== null ? AssignmentScope::DEPARTMENT : AssignmentScope::FACILITY);
        $requestLogin = (bool) ($validated['request_login'] ?? false);
        if ($requestLogin) {
            $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff_user_link.manage'], $scopeChecker);
        }
        $photoDocumentId = null;
        if (($validated['photo'] ?? null) instanceof UploadedFile) {
            try {
                $photoDocumentId = $this->uploadStaffPhoto(
                    photo: $validated['photo'],
                    documents: $documents,
                    context: $context,
                    facilityId: $facilityId,
                    personId: $personId,
                    actorId: (string) $actor->getKey(),
                );
            } catch (\Exception $exception) {
                Log::error('HR staff photo could not be stored.', [
                    'actor_id' => (string) $actor->getKey(),
                    'tenant_id' => $context->tenantId(),
                    'facility_id' => $facilityId,
                    'person_id' => $personId,
                    'exception' => $exception,
                ]);

                return back()
                    ->with('error', 'Staff photo could not be stored. No staff record was created.')
                    ->withInput($request->except(['photo']));
            }
        }

        DB::transaction(function () use (
            $commandBus,
            $context,
            $actor,
            $validated,
            $personId,
            $staffId,
            $requestId,
            $assignmentRequests,
            $facilityId,
            $departmentId,
            $unitId,
            $scope,
            $position,
            $contractType,
            $audit,
            $requestLogin,
            $administration,
            $scopeChecker,
            $photoDocumentId,
        ): void {
            $commandBus->dispatch(new CreatePerson(
                firstName: $validated['first_name'],
                middleName: $validated['middle_name'] ?? null,
                lastName: $validated['last_name'],
                dateOfBirth: $validated['date_of_birth'],
                gender: $validated['gender'],
                personId: $personId,
                phone: $validated['phone'] ?? null,
                email: $validated['email'] ?? null,
                photoDocumentId: $photoDocumentId,
            ));
            $commandBus->dispatch(new CreateStaff(
                personId: $personId,
                staffId: $staffId,
                primaryCadreId: $position === null ? null : $position['cadreId'],
                primaryPositionId: $position === null ? null : $position['id'],
            ));
            if ($position !== null) {
                $commandBus->dispatch(new CreateEmployment(
                    staffId: $staffId,
                    positionId: $position['id'],
                    contractType: $contractType,
                    startDate: $validated['employment_start_date'],
                    fte: $validated['fte'] ?? '1.00',
                ));
            }
            $assignmentRequests->create(
                requestId: $requestId,
                tenantId: $context->tenantId(),
                staffId: $staffId,
                facilityId: $facilityId,
                departmentId: $departmentId,
                unitId: $unitId,
                scope: $scope,
                effectiveFrom: $validated['effective_from'],
                requesterId: (string) $actor->getKey(),
            );
            if ($requestLogin) {
                try {
                    $invitation = $administration->inviteFacilityUser(
                        tenantId: $context->tenantId(),
                        roleKey: array_values([
                            (string) $validated['primary_role_key'],
                            ...($validated['secondary_role_keys'] ?? []),
                        ]),
                        facilityId: $facilityId,
                        name: trim(implode(' ', array_filter([
                            $validated['first_name'],
                            $validated['middle_name'] ?? null,
                            $validated['last_name'],
                        ]))),
                        email: mb_strtolower(trim((string) $validated['email'])),
                        changeReference: (string) $validated['change_reference'],
                        reason: trim((string) $validated['access_reason']),
                        actor: $actor,
                        scopeChecker: $scopeChecker,
                        staffId: $staffId,
                    );
                } catch (\RuntimeException $exception) {
                    Log::notice('HR staff account invitation was rejected.', [
                        'actor_id' => (string) $actor->getKey(),
                        'tenant_id' => $context->tenantId(),
                        'facility_id' => $facilityId,
                        'roles' => [
                            (string) $validated['primary_role_key'],
                            ...($validated['secondary_role_keys'] ?? []),
                        ],
                        'reason' => $exception->getMessage(),
                    ]);
                    throw ValidationException::withMessages(['facility_role' => $exception->getMessage()]);
                }

                $commandBus->dispatch(new LinkStaffToUser(
                    staffId: $staffId,
                    userId: $invitation['userId'],
                    linkedBy: (string) $actor->getKey(),
                ));
            }
            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: 'hr.staff_assignment.requested',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: ['request' => $requestId, 'staff' => $staffId, 'scope' => $scope->value],
                targetType: 'staff',
                targetId: $staffId,
            );
        });

        $status = 'Staff created; placement pending confirmation.';
        if ($requestLogin) {
            $roleLabels = array_map(
                static fn (string $roleKey): string => FacilityUserRoleCatalog::label($roleKey) ?? $roleKey,
                [
                    (string) $validated['primary_role_key'],
                    ...($validated['secondary_role_keys'] ?? []),
                ],
            );
            $status .= ' Invitation sent for '.implode(', ', $roleLabels).'. Access approval may be required.';
        }

        return $requestLogin
            ? redirect()->route('hr.staff.show', ['staff' => $staffId, 'section' => 'access'])->with('status', $status)
            : redirect()->route('hr.staff.index')->with('status', $status);
    }

    public function decideAssignment(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityAssignmentRequestService $assignmentRequests,
        string $assignmentRequest,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageIdentityAssignments = $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        $canManageHrAssignments = $this->hasAll($permissions, ['hr.staff.manage', 'hr.assignment.manage']);
        abort_unless($canManageIdentityAssignments || $canManageHrAssignments, 403);
        if ($canManageIdentityAssignments && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }
        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'reject'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        abort_if($validated['decision'] === 'reject' && trim((string) ($validated['reason'] ?? '')) === '', 422, 'A reason is required when rejecting an assignment.');

        $assignmentRequests->decide(
            tenantId: $context->tenantId(),
            facilityId: $facilityId,
            requestId: $assignmentRequest,
            actorId: (string) $actor->getKey(),
            decision: $validated['decision'],
            reason: $validated['reason'] ?? null,
        );

        return redirect()->route('hr.staff.assignment-requests')->with('status', 'Facility assignment request '.($validated['decision'] === 'approve' ? 'confirmed.' : 'rejected.'));
    }

    public function resubmitAssignment(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        FacilityAssignmentRequestService $assignmentRequests,
        OrganizationAdminApi $organization,
        string $assignmentRequest,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, [
            'hr.person.manage',
            'hr.staff.manage',
            'hr.assignment.manage',
        ], $scopeChecker);
        $validated = $request->validate([
            'department_id' => ['nullable', 'uuid'],
            'unit_id' => ['nullable', 'uuid'],
            'effective_from' => ['required', 'date'],
        ]);
        $departmentId = $validated['department_id'] ?: null;
        $unitId = $validated['unit_id'] ?: null;
        if ($departmentId !== null) {
            abort_unless(
                $organization->activeDepartmentInFacility($context->tenantId(), $facilityId, $departmentId),
                422,
                'The selected department is not active in this facility.',
            );
        }
        if ($unitId !== null) {
            abort_unless(
                $departmentId !== null
                    && $organization->activeUnitInDepartment(
                        $context->tenantId(),
                        $facilityId,
                        $departmentId,
                        $unitId,
                    ),
                422,
                'The selected unit must be active in the selected department.',
            );
        }
        $scope = $unitId !== null
            ? AssignmentScope::UNIT
            : ($departmentId !== null ? AssignmentScope::DEPARTMENT : AssignmentScope::FACILITY);

        $assignmentRequests->resubmit(
            tenantId: $context->tenantId(),
            facilityId: $facilityId,
            requestId: $assignmentRequest,
            requesterId: (string) $actor->getKey(),
            departmentId: $departmentId,
            unitId: $unitId,
            scope: $scope,
            effectiveFrom: $validated['effective_from'],
        );

        return redirect()->route('hr.staff.assignment-requests')->with('status', 'Updated facility assignment request resubmitted for confirmation.');
    }

    public function storeCadre(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->authorizeFacilityPermissions($actor, $this->activeFacilityId($context), [
            'hr.cadre_position.manage',
        ], $scopeChecker);
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('hr_cadres', 'code')->where('tenant_id', $context->tenantId()),
            ],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::enum(CadreCategory::class)],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        $commandBus->dispatch(new DefineCadre(
            code: $validated['code'],
            name: $validated['name'],
            category: $validated['category'],
            description: $validated['description'] ?? null,
        ));

        return redirect()->route('hr.staff.positions')->with('status', 'Job cadre created.');
    }

    public function storePosition(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        FacilityStaffDirectoryService $directory,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $this->authorizeFacilityPermissions($actor, $this->activeFacilityId($context), [
            'hr.cadre_position.manage',
        ], $scopeChecker);
        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                'regex:/\\A[A-Z0-9]+(?:[_-][A-Z0-9]+)*\\z/',
                Rule::unique('hr_positions', 'code')->where('tenant_id', $context->tenantId()),
            ],
            'title' => ['required', 'string', 'max:150'],
            'cadre_id' => ['required', 'uuid'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);
        abort_unless(
            $directory->activeCadreExists($context->tenantId(), $validated['cadre_id']),
            422,
            'Select an active cadre in this tenant.',
        );
        $commandBus->dispatch(new DefinePosition(
            code: $validated['code'],
            title: $validated['title'],
            cadreId: $validated['cadre_id'],
            description: $validated['description'] ?? null,
        ));

        return redirect()->route('hr.staff.positions')->with('status', 'Job position created.');
    }

    public function onboardStaff(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        FacilityStaffDirectoryService $directory,
        FacilityStaffLifecycleService $lifecycle,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff.manage'], $scopeChecker);
        $validated = $request->validate([
            'joined_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        $staffRow = $directory->onboardableStaff(
            $context->tenantId(),
            $facilityId,
            $staff,
            now()->toDateString(),
        );
        abort_unless($staffRow !== null, 404);
        abort_unless($staffRow['status'] === 'PROSPECT', 409, 'Only a staff member pending onboarding with a confirmed active facility assignment can be onboarded.');

        $startedEmploymentId = $lifecycle->onboard(
            tenantId: $context->tenantId(),
            staffId: $staff,
            joinedDate: $validated['joined_date'],
            cadreId: $staffRow['primaryCadreId'],
            positionId: $staffRow['primaryPositionId'],
            startDraftEmployment: $scopeChecker->hasFacilityPermission($actor, $facilityId, 'hr.employment.manage'),
        );

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff.onboarded',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            metadata: [
                'joined_date' => $validated['joined_date'],
                'employment_started' => $startedEmploymentId !== null,
                'employment' => $startedEmploymentId,
            ],
            targetType: 'staff',
            targetId: $staff,
        );

        if ($startedEmploymentId !== null) {
            $audit->recordFor(
                tenantId: $context->tenantId(),
                action: 'hr.employment.started',
                actorId: (string) $actor->getKey(),
                facilityId: $facilityId,
                metadata: [
                    'employment' => $startedEmploymentId,
                    'start_date' => $validated['joined_date'],
                    'source' => 'STAFF_ONBOARDING',
                ],
                targetType: 'employment',
                targetId: $startedEmploymentId,
            );
        }

        return redirect()->route('hr.staff.show', $staff)->with(
            'status',
            $startedEmploymentId === null
                ? 'Staff member onboarded.'
                : 'Staff member onboarded and their employment started.',
        );
    }

    public function suspendStaff(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        FacilityStaffLifecycleService $lifecycle,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff.manage'], $scopeChecker);
        $this->assertStaffHasFacilityAssignment($context, $facilityId, $staff, $directory);
        $this->assertNotOwnLinkedStaff($context, $actor, $staff, $directory);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        try {
            $lifecycle->suspend(
                tenantId: $context->tenantId(),
                staffId: $staff,
                actorId: (string) $actor->getKey(),
                reason: trim($validated['reason']),
                audit: $audit,
            );
        } catch (InvalidStaffStatusTransitionException $exception) {
            return back()->withErrors(['lifecycle' => $exception->getMessage()]);
        }

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff.suspended',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            targetType: 'staff',
            targetId: $staff,
        );

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Staff member suspended. The linked account is blocked tenant-wide; ordinary access was suspended and privileged role changes require independent approval.');
    }

    public function reinstateStaff(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        FacilityStaffLifecycleService $lifecycle,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff.manage'], $scopeChecker);
        $this->assertStaffHasFacilityAssignment($context, $facilityId, $staff, $directory);
        $this->assertNotOwnLinkedStaff($context, $actor, $staff, $directory);

        try {
            $lifecycle->reinstate(
                tenantId: $context->tenantId(),
                staffId: $staff,
                actorId: (string) $actor->getKey(),
                audit: $audit,
            );
        } catch (InvalidStaffStatusTransitionException $exception) {
            return back()->withErrors(['lifecycle' => $exception->getMessage()]);
        }

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff.reinstated',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            targetType: 'staff',
            targetId: $staff,
        );

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Staff member reinstated. Only unchanged assignments suspended by this HR action were restored; privileged access changes require independent review.');
    }

    public function terminateStaff(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        AuditLogger $audit,
        FacilityStaffLifecycleService $lifecycle,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff.manage'], $scopeChecker);
        $this->assertStaffHasFacilityAssignment($context, $facilityId, $staff, $directory);
        $this->assertNotOwnLinkedStaff($context, $actor, $staff, $directory);
        $validated = $request->validate([
            'terminated_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:1000', 'not_regex:/^\s*$/'],
        ]);

        try {
            $lifecycle->terminate(
                tenantId: $context->tenantId(),
                staffId: $staff,
                actorId: (string) $actor->getKey(),
                terminatedDate: $validated['terminated_date'],
                reason: trim($validated['reason']),
                audit: $audit,
            );
        } catch (
            InvalidStaffStatusTransitionException
            |StaffHasActiveAssignmentsException
            |StaffHasActiveEmploymentException
            |StaffHasFutureRosterEntriesException $exception
        ) {
            return back()->withErrors(['lifecycle' => $exception->getMessage()]);
        }

        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff.terminated',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            metadata: ['terminated_date' => $validated['terminated_date']],
            targetType: 'staff',
            targetId: $staff,
        );

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Staff member terminated. The linked account is blocked tenant-wide; ordinary access was revoked and privileged role changes require independent approval.');
    }

    public function startEmployment(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        AuditLogger $audit,
        FacilityStaffDirectoryService $directory,
        string $staff,
        string $employment,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.employment.manage'], $scopeChecker);
        $validated = $request->validate([
            'start_date' => ['required', 'date', 'before_or_equal:today'],
        ]);
        abort_unless(
            $directory->canStartEmployment(
                $context->tenantId(),
                $facilityId,
                $staff,
                $employment,
                now()->toDateString(),
            ),
            404,
        );

        $commandBus->dispatch(new StartEmployment(
            employmentId: $employment,
            startDate: $validated['start_date'],
        ));
        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.employment.started',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            metadata: ['employment' => $employment, 'start_date' => $validated['start_date']],
            targetType: 'employment',
            targetId: $employment,
        );

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Employment started.');
    }

    public function updatePerson(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        AuditLogger $audit,
        DocumentsApi $documents,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.person.manage'], $scopeChecker);
        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::in(['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'])],
            'national_id' => ['nullable', 'string', 'max:50'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:150'],
            'physical_address' => ['nullable', 'string', 'max:2000'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $personId = $directory->editablePersonId(
            $context->tenantId(),
            $facilityId,
            $staff,
            now()->toDateString(),
        );
        abort_unless($personId !== null, 404);

        $photoDocumentId = null;
        if (($validated['photo'] ?? null) instanceof UploadedFile) {
            try {
                $photoDocumentId = $this->uploadStaffPhoto(
                    photo: $validated['photo'],
                    documents: $documents,
                    context: $context,
                    facilityId: $facilityId,
                    personId: $personId,
                    actorId: (string) $actor->getKey(),
                );
            } catch (\Exception $exception) {
                Log::error('HR staff photo could not be stored.', [
                    'actor_id' => (string) $actor->getKey(),
                    'tenant_id' => $context->tenantId(),
                    'facility_id' => $facilityId,
                    'person_id' => $personId,
                    'exception' => $exception,
                ]);

                return back()
                    ->with('error', 'Staff photo could not be stored. Staff details were not changed.')
                    ->withInput($request->except(['photo']));
            }
        }

        $commandBus->dispatch(new UpdatePerson(
            personId: $personId,
            firstName: $validated['first_name'],
            middleName: $validated['middle_name'] ?? null,
            lastName: $validated['last_name'],
            dateOfBirth: $validated['date_of_birth'],
            gender: $validated['gender'],
            nationalId: $validated['national_id'] ?? null,
            phone: $validated['phone'] ?? null,
            email: $validated['email'] ?? null,
            physicalAddress: $validated['physical_address'] ?? null,
            photoDocumentId: $photoDocumentId,
        ));
        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.person.updated',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            metadata: ['staff' => $staff, 'person' => $personId],
            targetType: 'person',
            targetId: $personId,
        );

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Staff details updated.');
    }

    private function uploadStaffPhoto(
        UploadedFile $photo,
        DocumentsApi $documents,
        TenantContext $context,
        string $facilityId,
        string $personId,
        string $actorId,
    ): string {
        $document = $documents->upload(new UploadDocumentDto(
            tenantId: (string) $context->tenantId(),
            facilityId: $facilityId,
            docType: DocumentType::STAFF_PHOTO,
            confidentialityLevel: ConfidentialityLevel::STANDARD,
            sourceDomain: 'HR',
            sourceEntityRef: $personId,
            fileContents: (string) $photo->get(),
            mimeType: (string) $photo->getMimeType(),
            fileName: (string) $photo->getClientOriginalName(),
            actorId: $actorId,
        ));

        return $document->id;
    }

    public function linkUser(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        FacilityStaffDirectoryService $directory,
        AccountDirectoryApi $accounts,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        $this->authorizeFacilityPermissions($actor, $facilityId, ['hr.staff_user_link.manage'], $scopeChecker);
        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $staffIsAssigned = $directory->isEligibleForAccountLink(
            $context->tenantId(),
            $facilityId,
            $staff,
            now()->toDateString(),
        );
        abort_unless($staffIsAssigned, 404);
        $userId = $accounts->activeVerifiedAccountIdByEmail($context->tenantId(), $validated['email']);
        abort_unless($userId !== null, 422, 'No active, verified account with that email exists in this tenant.');

        $commandBus->dispatch(new LinkStaffToUser(
            staffId: $staff,
            userId: $userId,
            linkedBy: (string) $actor->getKey(),
        ));

        return redirect()->route('hr.staff.show', $staff)->with('status', 'Staff record linked to the existing account. No additional access was granted.');
    }

    public function inviteStaffAccount(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        CommandBus $commandBus,
        PlatformAdministrationService $administration,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageIdentityAssignments = $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        $canManageHRAccountLinks = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        );
        abort_unless($canManageIdentityAssignments || $canManageHRAccountLinks, 403);
        if ($canManageIdentityAssignments && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'primary_role_key' => ['required', 'string', Rule::in(array_keys(FacilityUserRoleCatalog::STAFF_ROLES))],
            'secondary_role_keys' => ['sometimes', 'array', 'max:2'],
            'secondary_role_keys.*' => [
                'string',
                'distinct',
                Rule::in(array_keys(FacilityUserRoleCatalog::STAFF_ROLES)),
                Rule::notIn([(string) $request->input('primary_role_key', '')]),
            ],
            'change_reference' => ['required', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'access_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $staffRow = $directory->staffEligibleForInvitation(
            $context->tenantId(),
            $facilityId,
            $staff,
            now()->toDateString(),
        );
        abort_unless($staffRow !== null, 404, 'The staff member must have an active facility placement and no linked account.');

        $roleKeys = array_values([
            (string) $validated['primary_role_key'],
            ...($validated['secondary_role_keys'] ?? []),
        ]);
        try {
            DB::transaction(function () use (
                $context,
                $actor,
                $scopeChecker,
                $administration,
                $commandBus,
                $validated,
                $roleKeys,
                $facilityId,
                $staffRow,
            ): void {
                $invitation = $administration->inviteFacilityUser(
                    tenantId: $context->tenantId(),
                    roleKey: $roleKeys,
                    facilityId: $facilityId,
                    name: trim(implode(' ', array_filter([
                        $staffRow['firstName'],
                        $staffRow['middleName'],
                        $staffRow['lastName'],
                    ]))),
                    email: mb_strtolower(trim((string) $validated['email'])),
                    changeReference: (string) $validated['change_reference'],
                    reason: trim((string) $validated['access_reason']),
                    actor: $actor,
                    scopeChecker: $scopeChecker,
                    staffId: $staffRow['id'],
                );
                $commandBus->dispatch(new LinkStaffToUser(
                    staffId: $staffRow['id'],
                    userId: $invitation['userId'],
                    linkedBy: (string) $actor->getKey(),
                ));
            });
        } catch (\RuntimeException $exception) {
            Log::notice('HR staff account invitation was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $context->tenantId(),
                'facility_id' => $facilityId,
                'staff_id' => $staff,
                'roles' => $roleKeys,
                'reason' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['email' => $exception->getMessage()]);
        }

        return redirect()->route('hr.staff.show', ['staff' => $staff, 'section' => 'access'])
            ->with('status', 'Account invitation sent and linked to this staff record. After setup, approve any pending facility-role requests from this page.');
    }

    public function resendStaffInvitation(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        PlatformAdministrationService $administration,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageIdentityAssignments = $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        $canManageHRAccountLinks = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        );
        abort_unless($canManageIdentityAssignments || $canManageHRAccountLinks, 403);
        if ($canManageIdentityAssignments && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $validated = $request->validate([
            'change_reference' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/'],
            'access_reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $today = now()->toDateString();
        $linkedUserId = $directory->linkedUserId($context->tenantId(), $staff);
        abort_unless($linkedUserId !== null, 404);
        $staffProfile = $directory->staffProfile(
            $context->tenantId(),
            $facilityId,
            $staff,
            $today,
        );
        abort_unless(
            $staffProfile !== null
                && in_array((string) $staffProfile['staff_status'], ['PROSPECT', 'ACTIVE'], true)
                && $directory->hasCurrentFacilityAssignment(
                    $context->tenantId(),
                    $facilityId,
                    $staff,
                    $today,
                ),
            404,
        );
        $invitation = $administration->pendingFacilityInvitationForAccount(
            $context->tenantId(),
            $facilityId,
            $linkedUserId,
        );
        abort_unless($invitation !== null && $invitation->userId === $linkedUserId, 404);
        if (! $invitation->canResend()) {
            throw ValidationException::withMessages([
                'invitation' => 'Only an unused, unaccepted setup invitation for an active account that has not verified its email can be resent.',
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
                        'invitation' => 'The invitation role data is invalid. Contact an administrator.',
                    ]);
                }
                $roleKeys[] = $roleKey;
            }
        }
        if ($roleKeys === [] && is_string($invitation->roleKey)) {
            $roleKeys = [$invitation->roleKey];
        }
        if ($roleKeys === []) {
            throw ValidationException::withMessages([
                'invitation' => 'The invitation has no roles to resend. Contact an administrator.',
            ]);
        }

        try {
            $administration->inviteFacilityUser(
                tenantId: $context->tenantId(),
                roleKey: $roleKeys,
                facilityId: $facilityId,
                name: $invitation->name,
                email: mb_strtolower($invitation->email),
                changeReference: $validated['change_reference'],
                reason: trim($validated['access_reason']),
                actor: $actor,
                scopeChecker: $scopeChecker,
                staffId: $staff,
            );
        } catch (\RuntimeException $exception) {
            Log::notice('HR staff account invitation resend was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $context->tenantId(),
                'facility_id' => $facilityId,
                'staff_id' => $staff,
                'invitation_id' => $invitation->invitationId,
                'reason' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return redirect()->route('hr.staff.show', ['staff' => $staff, 'section' => 'access'])
            ->with('status', 'A fresh setup invitation was sent. The link expires in 60 minutes.');
    }

    public function revokeStaffInvitation(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        PlatformAdministrationService $administration,
        FacilityStaffDirectoryService $directory,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        $canManageIdentityAssignments = $this->hasAll(
            $permissions,
            ['admin.workspace.access', 'identity.assignments.manage'],
        );
        $canManageHRAccountLinks = $this->hasAll(
            $permissions,
            ['hr.staff.manage', 'hr.staff_user_link.manage'],
        );
        abort_unless($canManageIdentityAssignments || $canManageHRAccountLinks, 403);
        if ($canManageIdentityAssignments && ! $actor->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('admin.security.two-factor.setup');
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $linkedUserId = $directory->linkedUserId($context->tenantId(), $staff);
        abort_unless($linkedUserId !== null, 404);
        $invitation = $administration->pendingFacilityInvitationForAccount(
            $context->tenantId(),
            $facilityId,
            $linkedUserId,
        );
        abort_unless($invitation !== null, 404);
        if (! $invitation->canRevoke()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation can no longer be revoked. It may already be accepted, replaced or revoked.',
            ]);
        }

        try {
            $administration->revokeFacilityInvitation(
                tenantId: $context->tenantId(),
                facilityId: $facilityId,
                invitationId: $invitation->invitationId,
                actor: $actor,
                reason: trim($validated['reason']),
                staffId: $staff,
            );
        } catch (\RuntimeException $exception) {
            Log::notice('HR staff account invitation revocation was rejected.', [
                'actor_id' => (string) $actor->getKey(),
                'tenant_id' => $context->tenantId(),
                'facility_id' => $facilityId,
                'staff_id' => $staff,
                'invitation_id' => $invitation->invitationId,
                'reason' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['invitation' => $exception->getMessage()]);
        }

        return redirect()->route('hr.staff.show', ['staff' => $staff, 'section' => 'access'])
            ->with('status', 'The setup invitation was revoked. The account was not activated and no access was granted.');
    }

    public function unlinkStaffAccount(
        Request $request,
        TenantContext $context,
        ScopeChecker $scopeChecker,
        PlatformAdministrationService $administration,
        FacilityStaffDirectoryService $directory,
        IdentityApi $identity,
        OrganizationAdminApi $organizationAdminApi,
        CommandBus $commandBus,
        AuditLogger $audit,
        string $staff,
    ): RedirectResponse {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $facilityId = $this->activeFacilityId($context);
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        $permissions = $scopeChecker->effectivePermissions($actor, $facilityId);
        abort_unless(
            $this->hasAll($permissions, ['hr.staff.manage', 'hr.staff_user_link.manage']),
            403,
        );
        $this->assertStaffHasFacilityAssignment($context, $facilityId, $staff, $directory);
        $this->assertNotOwnLinkedStaff($context, $actor, $staff, $directory);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:8', 'max:500'],
        ]);
        $linkedUserId = $directory->linkedUserId($context->tenantId(), $staff);
        abort_unless($linkedUserId !== null, 404);
        $invitation = $administration->pendingFacilityInvitationForAccount(
            $context->tenantId(),
            $facilityId,
            $linkedUserId,
        );
        if ($invitation === null
            || $invitation->emailVerifiedAt !== null
            || $invitation->consumedAt !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Only an unactivated setup account can be unlinked. Revoke its facility access first if the account has already been activated.',
            ]);
        }

        $facility = $organizationAdminApi->activeFacilitySummary($context->tenantId(), $facilityId);
        abort_unless($facility !== null, 404);
        $assignments = $identity->facilityAccountAssignments(
            $context->tenantId(),
            $facilityId,
            (string) $facility['organizationId'],
            $linkedUserId,
        );
        if ($assignments !== []) {
            throw ValidationException::withMessages([
                'invitation' => 'This account already has facility assignments. Revoke those assignments before unlinking the staff record.',
            ]);
        }

        if ($invitation->canRevoke()) {
            $administration->revokeFacilityInvitation(
                tenantId: $context->tenantId(),
                facilityId: $facilityId,
                invitationId: $invitation->invitationId,
                actor: $actor,
                reason: trim($validated['reason']),
                staffId: $staff,
            );
        }

        $commandBus->dispatch(new UnlinkStaffFromUser(
            staffId: $staff,
            reason: trim($validated['reason']),
            unlinkedBy: (string) $actor->getKey(),
        ));
        $audit->recordFor(
            tenantId: $context->tenantId(),
            action: 'hr.staff_user.unlinked_for_recovery',
            actorId: (string) $actor->getKey(),
            facilityId: $facilityId,
            reason: trim($validated['reason']),
            metadata: ['user_id' => $linkedUserId],
            targetType: 'staff',
            targetId: $staff,
        );

        return redirect()->route('hr.staff.show', ['staff' => $staff, 'section' => 'access'])
            ->with('status', 'The unactivated account was unlinked. You can now invite the correct email or link an existing account.');
    }

    /**
     * @param  list<string>  $required
     */
    private function authorizeFacilityPermissions(
        User $actor,
        string $facilityId,
        array $required,
        ScopeChecker $scopeChecker,
    ): void {
        abort_unless($scopeChecker->withinFacilityScope($actor, $facilityId), 403);
        abort_unless($this->hasAll($scopeChecker->effectivePermissions($actor, $facilityId), $required), 403);
    }

    private function assertStaffHasFacilityAssignment(
        TenantContext $context,
        string $facilityId,
        string $staffId,
        FacilityStaffDirectoryService $directory,
    ): void {
        abort_unless($directory->hasFacilityAssignment($context->tenantId(), $facilityId, $staffId), 404);
    }

    private function assertNotOwnLinkedStaff(
        TenantContext $context,
        User $actor,
        string $staffId,
        FacilityStaffDirectoryService $directory,
    ): void {
        $linkedUserId = $directory->linkedUserId($context->tenantId(), $staffId);

        abort_if(
            is_string($linkedUserId) && $linkedUserId === (string) $actor->getKey(),
            403,
            'You cannot change the lifecycle status of your own linked staff record.',
        );
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $required
     */
    private function hasAll(array $permissions, array $required): bool
    {
        return array_diff($required, $permissions) === [];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function canReviewAssignments(array $permissions): bool
    {
        return $this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage'])
            || $this->hasAll($permissions, ['hr.staff.manage', 'hr.assignment.manage']);
    }

    /**
     * @param  list<string>  $permissions
     * @param  list<string>  $allowed
     */
    private function hasAny(array $permissions, array $allowed): bool
    {
        return array_intersect($allowed, $permissions) !== [];
    }

    private function activeFacilityId(TenantContext $context): string
    {
        $facilityId = $context->facilityId();
        abort_unless(is_string($facilityId) && $facilityId !== '', 409, 'Select an active facility before managing staff.');

        return $facilityId;
    }

    /**
     * @return array{id: string, name: string}
     */
    private function facilitySummary(TenantContext $context, string $facilityId): array
    {
        $facility = app(OrganizationAdminApi::class)->activeFacilitySummary(
            $context->tenantId(),
            $facilityId,
        );
        abort_unless($facility !== null, 404);

        return ['id' => $facility['id'], 'name' => $facility['name']];
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function selectableHRFacilities(
        User $actor,
        TenantContext $context,
        ScopeChecker $scopeChecker,
    ): array {
        $facilityIds = $scopeChecker->selectableFacilities($actor);
        if ($facilityIds === []) {
            return [];
        }

        $facilities = app(OrganizationAdminApi::class)->activeFacilities(
            $context->tenantId(),
            $facilityIds,
        );

        return array_values(array_map(
            static fn (array $facility): array => [
                'id' => $facility['id'],
                'name' => $facility['name'],
            ],
            array_filter($facilities, function (array $facility) use ($actor, $scopeChecker): bool {
                $permissions = $scopeChecker->effectivePermissions($actor, $facility['id']);

                return $this->hasAny($permissions, ['hr.staff.read', 'hr.staff.manage'])
                    || $this->hasAll($permissions, ['admin.workspace.access', 'identity.assignments.manage']);
            }),
        ));
    }

    /**
     * @return list<array{id: string, name: string}>
     */
    private function departments(TenantContext $context, string $facilityId): array
    {
        return app(OrganizationAdminApi::class)->activeDepartments($context->tenantId(), $facilityId);
    }

    /**
     * @return list<array{id: string, departmentId: string, name: string}>
     */
    private function units(TenantContext $context, string $facilityId): array
    {
        return app(OrganizationAdminApi::class)->activeUnits($context->tenantId(), $facilityId);
    }

    /**
     * @return list<array{id: string, code: string, title: string, cadreId: string}>
     */
    private function positionsList(TenantContext $context): array
    {
        return app(FacilityStaffDirectoryService::class)->activePositions($context->tenantId());
    }

    /**
     * @return list<array{key: string, label: string, requiredCapability?: string}>
     */
    private function facilityInvitationRoles(TenantContext $context, string $facilityId): array
    {
        $unavailableRoleKeys = app(IdentityApi::class)->unavailableFacilityRoleKeys(
            $context->tenantId(),
            array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
        );
        $enabledCapabilities = app(OrganizationApi::class)
            ->enabledCapabilitiesForFacilities([$facilityId])[$facilityId] ?? [];
        // Catalog order is stable UI order. Offers come from the closed
        // catalog — never from which rows happen to exist.
        $roleKeys = array_values(array_filter(
            array_keys(FacilityUserRoleCatalog::STAFF_ROLES),
            static fn (string $roleKey): bool => ! in_array($roleKey, $unavailableRoleKeys, true)
                && FacilityUserRoleCatalog::isAvailableAtFacility($roleKey, $enabledCapabilities),
        ));

        return FacilityUserRoleCatalog::options($roleKeys);
    }

    /** @return list<string> */
    private function invitationRoleKeys(?string $roleKeys, ?string $roleKey): array
    {
        $decoded = $roleKeys === null ? null : json_decode($roleKeys, true);
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, 'is_string'));
        }

        return $roleKey === null || $roleKey === '' ? [] : [$roleKey];
    }

    /**
     * @return list<array{id: string, code: string, name: string, category: string}>
     */
    private function cadresList(TenantContext $context): array
    {
        return app(FacilityStaffDirectoryService::class)->activeCadres($context->tenantId());
    }
}
