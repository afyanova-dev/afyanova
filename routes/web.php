<?php

declare(strict_types=1);

use App\Domains\Authentication\Presentation\Http\Controller\FacilityUserInvitationController;
use App\Domains\Authentication\Presentation\Http\Middleware\RequireApprovedAdministrativeAccess;
use App\Domains\HR\Presentation\Http\Controller\FacilityPeopleSearchController;
use App\Domains\HR\Presentation\Http\Controller\FacilityStaffController;
use App\Domains\HR\Presentation\Http\Controller\StaffPhotoController;
use App\Domains\HR\Presentation\Http\Controller\StaffQualificationController;
use App\Domains\Identity\Presentation\Http\Controller\AdminRolePromotionController;
use App\Domains\Organization\Presentation\Http\Controller\AdminOrganizationController;
use App\Domains\Procurement\Presentation\Http\Controller\AdminProcurementLimitsController;
use App\Shared\Http\AdminAuditController;
use App\Shared\Http\AdminWorkspaceController;
use App\Shared\Http\HealthController;
use App\Shared\Http\Middleware\RequireAdminTwoFactorSetup;
use App\Shared\Http\Middleware\RequireAdminWorkspace;
use Illuminate\Support\Facades\Route;

/*

 */

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::get('/system/health', HealthController::class)->name('system.health');

Route::middleware(['auth', 'verified', RequireApprovedAdministrativeAccess::class])->group(function (): void {
    Route::get('/workspaces/hr/api/search', [FacilityPeopleSearchController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('hr.people.search');

    Route::get('/workspaces/hr/users/{user}', [FacilityPeopleSearchController::class, 'show'])
        ->whereUuid('user')
        ->name('hr.users.show');

    Route::get('/workspaces/hr/staff', [FacilityStaffController::class, 'index'])
        ->name('hr.staff.index');

    Route::get('/workspaces/hr/staff/create', [FacilityStaffController::class, 'create'])
        ->name('hr.staff.create');

    Route::get('/workspaces/hr/staff/{staff}/photo', StaffPhotoController::class)
        ->whereUuid('staff')
        ->name('hr.staff.photo');

    Route::get('/workspaces/hr/staff/onboarding', [FacilityStaffController::class, 'onboarding'])
        ->name('hr.staff.onboarding');

    Route::get('/workspaces/hr/staff/assignment-requests', [FacilityStaffController::class, 'assignmentRequests'])
        ->name('hr.staff.assignment-requests');

    Route::get('/workspaces/hr/staff/account-invitations', [FacilityStaffController::class, 'accountInvitations'])
        ->name('hr.staff.account-invitations');

    Route::get('/workspaces/hr/staff/access-requests', [FacilityStaffController::class, 'accessRequests'])
        ->name('hr.staff.access-requests');

    Route::get('/workspaces/hr/staff/positions', [FacilityStaffController::class, 'positions'])
        ->name('hr.staff.positions');

    Route::get('/workspaces/hr/staff/{staff}', [FacilityStaffController::class, 'show'])
        ->whereUuid('staff')
        ->name('hr.staff.show');

    Route::post('/workspaces/hr/staff', [FacilityStaffController::class, 'store'])
        ->name('hr.staff.store');

    Route::put('/workspaces/hr/staff/{staff}/person', [FacilityStaffController::class, 'updatePerson'])
        ->name('hr.staff.person.update');

    Route::post('/workspaces/hr/staff/{staff}/qualifications', [StaffQualificationController::class, 'store'])
        ->whereUuid('staff')
        ->name('hr.staff.qualifications.store');

    Route::put('/workspaces/hr/staff/{staff}/qualifications/{qualification}', [StaffQualificationController::class, 'update'])
        ->whereUuid('staff')
        ->whereUuid('qualification')
        ->name('hr.staff.qualifications.update');

    Route::post('/workspaces/hr/staff/{staff}/onboard', [FacilityStaffController::class, 'onboardStaff'])
        ->name('hr.staff.onboard');

    Route::post('/workspaces/hr/staff/{staff}/suspend', [FacilityStaffController::class, 'suspendStaff'])
        ->name('hr.staff.suspend');

    Route::post('/workspaces/hr/staff/{staff}/reinstate', [FacilityStaffController::class, 'reinstateStaff'])
        ->name('hr.staff.reinstate');

    Route::post('/workspaces/hr/staff/{staff}/terminate', [FacilityStaffController::class, 'terminateStaff'])
        ->name('hr.staff.terminate');

    Route::post('/workspaces/hr/staff/{staff}/employments/{employment}/start', [FacilityStaffController::class, 'startEmployment'])
        ->name('hr.staff.employment.start');

    Route::post('/workspaces/hr/staff/positions/cadres', [FacilityStaffController::class, 'storeCadre'])
        ->name('hr.staff.cadres.store');

    Route::post('/workspaces/hr/staff/positions', [FacilityStaffController::class, 'storePosition'])
        ->name('hr.staff.positions.store');

    Route::post('/workspaces/hr/staff/assignment-requests/{assignmentRequest}/decision', [FacilityStaffController::class, 'decideAssignment'])
        ->name('hr.staff.assignment-requests.decide');

    Route::post('/workspaces/hr/staff/assignment-requests/{assignmentRequest}/resubmit', [FacilityStaffController::class, 'resubmitAssignment'])
        ->name('hr.staff.assignment-requests.resubmit');

    Route::post('/workspaces/hr/staff/{staff}/link', [FacilityStaffController::class, 'linkUser'])
        ->name('hr.staff.link');

    Route::post('/workspaces/hr/staff/{staff}/invite-account', [FacilityStaffController::class, 'inviteStaffAccount'])
        ->name('hr.staff.invite-account');

    Route::post('/workspaces/hr/staff/{staff}/resend-invitation', [FacilityStaffController::class, 'resendStaffInvitation'])
        ->name('hr.staff.resend-invitation');

    Route::post('/workspaces/hr/staff/{staff}/revoke-invitation', [FacilityStaffController::class, 'revokeStaffInvitation'])
        ->name('hr.staff.revoke-invitation');

    Route::post('/workspaces/hr/staff/{staff}/unlink-account', [FacilityStaffController::class, 'unlinkStaffAccount'])
        ->name('hr.staff.unlink-account');

    Route::post('/workspaces/hr/staff/{staff}/access-assignments/{assignment}/{action}', [FacilityStaffController::class, 'manageAccessAssignment'])
        ->whereUuid('staff')
        ->whereUuid('assignment')
        ->whereIn('action', ['suspend', 'restore', 'revoke'])
        ->name('hr.staff.access-assignments.manage');

    Route::post('/workspaces/hr/access-requests/{promotion}/staff/{staff}/approve', [AdminRolePromotionController::class, 'approve'])
        ->whereUuid('staff')
        ->whereUuid('promotion')
        ->name('hr.staff.access-requests.approve');

    Route::post('/workspaces/hr/access-requests/{promotion}/staff/{staff}/reject', [AdminRolePromotionController::class, 'reject'])
        ->whereUuid('staff')
        ->whereUuid('promotion')
        ->name('hr.staff.access-requests.reject');

    Route::get('/workspaces/admin', [AdminWorkspaceController::class, 'index'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.index');

    Route::get('/workspaces/admin/audit', AdminAuditController::class)
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.audit');

    Route::get('/workspaces/admin/procurement-limits', [AdminProcurementLimitsController::class, 'index'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.procurement-limits');

    Route::post('/workspaces/admin/procurement-limits', [AdminProcurementLimitsController::class, 'store'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.procurement-limits.store');

    Route::get('/workspaces/admin/organization', AdminOrganizationController::class)
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization');

    Route::post('/workspaces/admin/organization/facilities', [AdminOrganizationController::class, 'store'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.facilities.store');

    Route::post('/workspaces/admin/organization/departments', [AdminOrganizationController::class, 'storeDepartment'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.departments.store');

    Route::post('/workspaces/admin/organization/units', [AdminOrganizationController::class, 'storeUnit'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.units.store');

    Route::post('/workspaces/admin/organization/rooms', [AdminOrganizationController::class, 'storeRoom'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.rooms.store');

    Route::put('/workspaces/admin/organization/departments/{department}', [AdminOrganizationController::class, 'updateDepartment'])
        ->whereUuid('department')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.departments.update');
    Route::post('/workspaces/admin/organization/departments/{department}/status', [AdminOrganizationController::class, 'setDepartmentStatus'])
        ->whereUuid('department')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.departments.status');
    Route::put('/workspaces/admin/organization/units/{unit}', [AdminOrganizationController::class, 'updateUnit'])
        ->whereUuid('unit')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.units.update');
    Route::post('/workspaces/admin/organization/units/{unit}/status', [AdminOrganizationController::class, 'setUnitStatus'])
        ->whereUuid('unit')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.units.status');
    Route::post('/workspaces/admin/organization/capabilities/{capability}/status', [AdminOrganizationController::class, 'setCapabilityState'])
        ->where('capability', '[A-Z_]+')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.capabilities.status');
    Route::put('/workspaces/admin/organization/rooms/{room}', [AdminOrganizationController::class, 'updateRoom'])
        ->whereUuid('room')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.rooms.update');
    Route::post('/workspaces/admin/organization/rooms/{room}/status', [AdminOrganizationController::class, 'setRoomStatus'])
        ->whereUuid('room')->middleware(RequireAdminWorkspace::class)
        ->name('admin.workspace.organization.rooms.status');

    Route::get('/workspaces/admin/identity/role-promotions', [AdminRolePromotionController::class, 'index'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.role-promotions');

    Route::post('/workspaces/admin/identity/role-promotions', [AdminRolePromotionController::class, 'store'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.role-promotions.store');

    Route::get('/workspaces/admin/identity/facility-assignment-targets', [AdminRolePromotionController::class, 'ordinaryGrantTargets'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.facility-assignment-targets');

    Route::post('/workspaces/admin/identity/role-promotions/facility-admin-invitations', FacilityUserInvitationController::class)
        ->middleware([RequireAdminWorkspace::class, 'throttle:10,1'])
        ->name('admin.identity.role-promotions.facility-admin-invitations.store');

    Route::post('/workspaces/admin/identity/role-promotions/facility-user-invitations', FacilityUserInvitationController::class)
        ->middleware([RequireAdminWorkspace::class, 'throttle:10,1'])
        ->name('admin.identity.role-promotions.facility-user-invitations.store');

    Route::post('/workspaces/admin/identity/role-promotions/facility-user-invitations/{invitationId}/resend', [FacilityUserInvitationController::class, 'resend'])
        ->middleware([RequireAdminWorkspace::class, 'throttle:10,1'])
        ->name('admin.identity.role-promotions.facility-user-invitations.resend');

    Route::post('/workspaces/admin/identity/facility-staff-managers', [AdminRolePromotionController::class, 'grantFacilityStaffManager'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.facility-staff-managers.store');

    Route::delete('/workspaces/admin/identity/facility-staff-managers/{assignment}', [AdminRolePromotionController::class, 'revokeFacilityStaffManager'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.facility-staff-managers.destroy');

    Route::post('/workspaces/admin/identity/facility-assignments', [AdminRolePromotionController::class, 'grantFacilityOrdinary'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.facility-assignments.store');

    Route::delete('/workspaces/admin/identity/facility-assignments/{assignment}', [AdminRolePromotionController::class, 'revokeFacilityOrdinary'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.facility-assignments.destroy');

    Route::post('/workspaces/admin/identity/role-promotions/{promotion}/approve', [AdminRolePromotionController::class, 'approve'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.role-promotions.approve');

    Route::post('/workspaces/admin/identity/role-promotions/{promotion}/reject', [AdminRolePromotionController::class, 'reject'])
        ->middleware(RequireAdminWorkspace::class)
        ->name('admin.identity.role-promotions.reject');

    Route::get('/admin/security/two-factor', [AdminWorkspaceController::class, 'setupTwoFactor'])
        ->middleware([RequireAdminTwoFactorSetup::class, 'password.confirm'])
        ->name('admin.security.two-factor.setup');
});
