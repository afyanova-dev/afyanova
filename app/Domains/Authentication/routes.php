<?php

declare(strict_types=1);

use App\Domains\Authentication\Presentation\Http\Controller\AdminBootstrapController;
use App\Domains\Authentication\Presentation\Http\Controller\FacilityContextController;
use App\Domains\Authentication\Presentation\Http\Controller\PlatformAdminController;
use App\Domains\Authentication\Presentation\Http\Controller\PlatformAuditController;
use App\Domains\Authentication\Presentation\Http\Controller\PlatformSuperAdminAccessController;
use App\Domains\Authentication\Presentation\Http\Controller\PlatformSuperAdminBootstrapController;
use App\Domains\Authentication\Presentation\Http\Controller\SessionsController;
use App\Domains\Authentication\Presentation\Http\Controller\StaffOnboardingPendingController;
use App\Domains\Authentication\Presentation\Http\Middleware\RequireApprovedAdministrativeAccess;
use App\Http\Controllers\DashboardController;
use App\Shared\Http\AdminWorkspaceController;
use App\Shared\Http\Middleware\RequirePlatformSuperAdmin;
use App\Shared\Http\Middleware\RequirePlatformSuperAdminSetup;
use Illuminate\Support\Facades\Route;

/*
 | Authentication module routes (loaded by AuthenticationServiceProvider).
 | Fortify registers login / logout / password / 2FA / email-verification
 | itself; these are the AfyaNova additions that sit on top.
 */

Route::middleware(['web', 'auth', 'verified', RequireApprovedAdministrativeAccess::class])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::get('/staff/onboarding-pending', StaffOnboardingPendingController::class)
        ->name('staff.onboarding-pending');

    Route::post('/facility-context', [FacilityContextController::class, 'update'])->name('facility-context.update');

    Route::get('/settings/sessions', [SessionsController::class, 'index'])->name('sessions.index');
    Route::delete('/settings/sessions/{id}', [SessionsController::class, 'destroy'])->name('sessions.destroy');

    Route::middleware(RequirePlatformSuperAdmin::class)->prefix('platform-admin')->name('platform-admin.')->group(function (): void {
        Route::get('/', [PlatformAdminController::class, 'index'])->name('index');
        Route::get('/search', [PlatformAdminController::class, 'search'])
            ->middleware('throttle:60,1')
            ->name('search');
        Route::get('/audit', PlatformAuditController::class)
            ->name('audit.index');
        Route::get('/super-admins', [PlatformSuperAdminAccessController::class, 'index'])
            ->name('super-admins.index');
        Route::post('/super-admin-access-requests', [PlatformSuperAdminAccessController::class, 'requestChange'])
            ->middleware('throttle:10,1')
            ->name('super-admin-access-requests.store');
        Route::post('/super-admin-access-requests/{requestId}/review', [PlatformSuperAdminAccessController::class, 'review'])
            ->whereUuid('requestId')
            ->middleware('throttle:10,1')
            ->name('super-admin-access-requests.review');
        Route::get('/tenants', [PlatformAdminController::class, 'tenants'])->name('tenants.index');
        Route::get('/tenants/create', [PlatformAdminController::class, 'createTenantForm'])->name('tenants.create');
        Route::post('/tenants', [PlatformAdminController::class, 'createTenant'])
            ->middleware('throttle:10,1')
            ->name('tenants.store');
        Route::get('/tenants/{tenantId}', [PlatformAdminController::class, 'showTenant'])
            ->whereUuid('tenantId')
            ->name('tenants.show');
        Route::post('/tenants/{tenantId}/status', [PlatformAdminController::class, 'updateTenantStatus'])
            ->whereUuid('tenantId')
            ->middleware('throttle:10,1')
            ->name('tenants.status.update');
        Route::get('/tenants/{tenantId}/facilities/{facilityId}', [PlatformAdminController::class, 'showFacility'])
            ->whereUuid('tenantId')
            ->whereUuid('facilityId')
            ->name('facilities.show');
        Route::patch('/tenants/{tenantId}/facilities/{facilityId}', [PlatformAdminController::class, 'updateFacility'])
            ->whereUuid('tenantId')
            ->whereUuid('facilityId')
            ->name('facilities.update');
        Route::post('/tenants/{tenantId}/facilities/{facilityId}/facility-admin-revocations', [PlatformAdminController::class, 'revokeFacilityAdmin'])
            ->whereUuid('tenantId')
            ->whereUuid('facilityId')
            ->name('facilities.admin-revocations.store');
        Route::post('/tenants/{tenantId}/tenant-admin-revocations', [PlatformAdminController::class, 'revokeTenantAdmin'])
            ->whereUuid('tenantId')
            ->middleware('throttle:10,1')
            ->name('tenant-admin-revocations.store');
        Route::post('/tenants/{tenantId}/tenant-admin-revocations/{reviewId}/review', [PlatformAdminController::class, 'reviewTenantAdminRevocation'])
            ->whereUuid('tenantId')
            ->whereUuid('reviewId')
            ->name('tenant-admin-revocations.review');
        Route::post('/tenants/{tenantId}/tenant-admin-invitations', [PlatformAdminController::class, 'inviteInitialTenantAdmin'])
            ->whereUuid('tenantId')
            ->middleware('throttle:10,1')
            ->name('tenant-admin-invitations.store');
    });

    Route::middleware(RequirePlatformSuperAdminSetup::class)->get(
        '/platform-admin/security/two-factor',
        [AdminWorkspaceController::class, 'setupTwoFactor'],
    )->middleware('password.confirm')->name('platform-admin.security.two-factor.setup');
});

Route::middleware('web')->group(function (): void {
    Route::get('/admin/first-access', [AdminBootstrapController::class, 'show'])
        ->name('admin.bootstrap.form');
    Route::post('/admin/first-access', [AdminBootstrapController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('admin.bootstrap.complete');

    Route::get('/platform-admin/first-access', [PlatformSuperAdminBootstrapController::class, 'show'])
        ->name('platform-admin.bootstrap.form');
    Route::post('/platform-admin/first-access', [PlatformSuperAdminBootstrapController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('platform-admin.bootstrap.complete');
});
