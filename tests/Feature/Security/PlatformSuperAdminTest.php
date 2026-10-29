<?php

declare(strict_types=1);

use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Authentication\Mail\PlatformSuperAdminInvitation;
use App\Domains\Authentication\Support\OneTimeInvitationMailGuard;
use App\Domains\Identity\Contracts\FacilityAdminRoleDefinition;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

function platformAdminBootstrapToken(): string
{
    $mail = Mail::sent(PlatformSuperAdminInvitation::class)->last();
    expect($mail)->toBeInstanceOf(PlatformSuperAdminInvitation::class);
    preg_match('/#([A-Za-z0-9]{64})/', $mail->render(), $matches);

    expect($matches[1] ?? null)->toBeString();

    return $matches[1];
}

function createPlatformAdmin(bool $twoFactorEnabled = false): User
{
    $user = User::factory()->state([
        'tenant_id' => null,
        'email' => fake()->unique()->safeEmail(),
    ])->create($twoFactorEnabled ? [
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ] : []);

    DB::table('platform_super_admins')->insert([
        'id' => (string) Str::uuid7(),
        'user_id' => $user->getKey(),
        'status' => 'ACTIVE',
        'created_by_reference' => 'OPS-BOOTSTRAP-001',
        'activated_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    session(['platform_access_version' => 0]);

    return $user;
}

it('provisions and activates the one-time platform Super Admin invitation', function (): void {
    Mail::fake();
    expect(Artisan::call('afyanova:platform-admin:bootstrap', [
        '--name' => 'Platform Administrator',
        '--email' => 'platform-admin@example.test',
        '--change-ref' => 'OPS-PLATFORM-101',
    ]))->toBe(0);
    $output = Artisan::output();
    Mail::assertSent(PlatformSuperAdminInvitation::class, fn (PlatformSuperAdminInvitation $mail): bool => $mail->hasTo('platform-admin@example.test'));

    $token = platformAdminBootstrapToken();
    expect($output)->not->toContain($token);
    $user = DB::table('users')->where('email', 'platform-admin@example.test')->first();
    expect($user->tenant_id)->toBeNull()
        ->and($user->status)->toBe('PENDING')
        ->and($user->email_verified_at)->toBeNull()
        ->and(DB::table('platform_super_admin_bootstrap_tokens')->where('user_id', $user->id)->value('token_hash'))
        ->toBe(hash('sha256', $token));

    $this->post(route('platform-admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongPlatformPassword!2026',
        'password_confirmation' => 'LongPlatformPassword!2026',
    ])->assertRedirect(route('login'));

    $user = User::query()->findOrFail($user->id);
    expect($user->isActive())->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('LongPlatformPassword!2026', $user->password))->toBeTrue()
        ->and($user->isPlatformSuperAdmin())->toBeTrue();
    expect(DB::table('platform_super_admin_bootstrap_tokens')->where('user_id', $user->getKey())->value('consumed_at'))
        ->not->toBeNull();

    $this->post(route('platform-admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'AnotherPlatformPassword!2026',
        'password_confirmation' => 'AnotherPlatformPassword!2026',
    ])->assertForbidden();
});

it('rotates a pending platform invitation only for the same email', function (): void {
    Mail::fake();
    $options = [
        '--name' => 'Platform Administrator',
        '--email' => 'platform-admin@example.test',
        '--change-ref' => 'OPS-PLATFORM-101',
    ];
    expect(Artisan::call('afyanova:platform-admin:bootstrap', $options))->toBe(0);
    $firstToken = platformAdminBootstrapToken();

    $options['--resend'] = true;
    $options['--change-ref'] = 'OPS-PLATFORM-102';
    expect(Artisan::call('afyanova:platform-admin:bootstrap', $options))->toBe(0);
    $secondToken = platformAdminBootstrapToken();
    expect($secondToken)->not->toBe($firstToken)
        ->and(DB::table('platform_super_admin_bootstrap_tokens')->count())->toBe(1);

    $this->post(route('platform-admin.bootstrap.complete'), [
        'token' => $firstToken,
        'password' => 'LongPlatformPassword!2026',
        'password_confirmation' => 'LongPlatformPassword!2026',
    ])->assertForbidden();
    $this->post(route('platform-admin.bootstrap.complete'), [
        'token' => $secondToken,
        'password' => 'LongPlatformPassword!2026',
        'password_confirmation' => 'LongPlatformPassword!2026',
    ])->assertRedirect(route('login'));
});

it('rejects an expired platform invitation without activating its identity', function (): void {
    Mail::fake();
    Artisan::call('afyanova:platform-admin:bootstrap', [
        '--name' => 'Platform Administrator',
        '--email' => 'platform-admin@example.test',
        '--change-ref' => 'OPS-PLATFORM-101',
    ]);
    $token = platformAdminBootstrapToken();
    DB::table('platform_super_admin_bootstrap_tokens')->update(['expires_at' => now()->subMinute()]);

    $this->post(route('platform-admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongPlatformPassword!2026',
        'password_confirmation' => 'LongPlatformPassword!2026',
    ])->assertForbidden();

    expect(DB::table('users')->where('email', 'platform-admin@example.test')->value('status'))->toBe('PENDING')
        ->and(DB::table('platform_super_admins')->value('status'))->toBe('PENDING');
});

it('refuses to create a setup link when the configured mailer would log it', function (): void {
    config(['mail.default' => 'log']);
    Mail::swap(new MailManager(app()));

    expect(Artisan::call('afyanova:platform-admin:bootstrap', [
        '--name' => 'Platform Administrator',
        '--email' => 'platform-admin@example.test',
        '--change-ref' => 'OPS-PLATFORM-101',
    ]))->toBe(1)
        ->and(Artisan::output())->toContain('cannot deliver setup links without writing their secret URL to logs');
    expect(DB::table('platform_super_admins')->exists())->toBeFalse()
        ->and(DB::table('platform_super_admin_bootstrap_tokens')->exists())->toBeFalse();

    config([
        'mail.default' => 'smtp',
        'mail.mailers.smtp.host' => 'smtp.gmail.com',
        'mail.mailers.smtp.port' => 587,
        'mail.mailers.smtp.username' => 'platform-admin@example.test',
        'mail.mailers.smtp.password' => '',
        'mail.from.address' => 'platform-admin@example.test',
    ]);
    expect(OneTimeInvitationMailGuard::canDeliver())->toBeFalse();
});

it('isolates platform routes from tenantless non-platform users and requires confirmed two-factor authentication', function (): void {
    $ordinaryUser = User::factory()->state(['tenant_id' => null])->create();
    $this->actingAs($ordinaryUser)->get(route('platform-admin.index'))->assertForbidden();

    $platformAdmin = createPlatformAdmin();
    $this->actingAs($platformAdmin)->get(route('platform-admin.index'))
        ->assertRedirect(route('platform-admin.security.two-factor.setup'));
    $this->actingAs($platformAdmin)->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('platform-admin.security.two-factor.setup'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/TwoFactorSetup')
            ->where('continueTo', route('platform-admin.index')));

    $platformAdmin->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['aaaa-bbbb', 'cccc-dddd'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
    $this->actingAs($platformAdmin)->get(route('platform-admin.index'))->assertOk();
    $this->actingAs($platformAdmin)->get(route('dashboard'))->assertRedirect(route('platform-admin.index'));
});

it('allows the platform Super Admin to invite the initial Tenant Admin with tenant audit attribution', function (): void {
    ['tenant' => $tenant] = makeTenantWithUser();
    $platformAdmin = createPlatformAdmin(twoFactorEnabled: true);
    Mail::fake();

    $this->actingAs($platformAdmin)->post(route('platform-admin.tenant-admin-invitations.store', ['tenantId' => $tenant->id]), [
        'name' => 'DSK Tenant Administrator',
        'email' => 'dskadmin@example.test',
        'change_reference' => 'OPS-DSK-ADMIN-101',
    ])->assertSessionHasNoErrors();

    Mail::assertSent(AdminBootstrapInvitation::class, fn ($mail): bool => $mail->hasTo('dskadmin@example.test'));
    $user = DB::table('users')->where('email', 'dskadmin@example.test')->first();
    expect($user)->not->toBeNull()
        ->and(DB::table('aut_admin_bootstrap_tokens')->where('user_id', $user->id)->value('token_hash'))
        ->toMatch('/^[a-f0-9]{64}$/');

    $this->actingAs($platformAdmin)->get(route('platform-admin.tenants.index'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenants.0.pendingAdminEmail', 'dskadmin@example.test')
            ->where('tenants.0.pendingAdminInvitationExpired', false));
    $this->actingAs($platformAdmin)
        ->get(route('platform-admin.tenants.show', ['tenantId' => $tenant->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('tenant.pendingAdminEmail', 'dskadmin@example.test')
            ->where('tenant.pendingAdminInvitationExpired', false));

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $platformAdmin, $user): void {
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->first();
        expect($assignment)->not->toBeNull()
            ->and($assignment->status)->toBe('PENDING')
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('target_id', $user->id)
                ->where('action', 'admin.tenant_admin.bootstrap_invited')
                ->exists())->toBeTrue();
    });
});

it('suspends a tenant, revokes its tracked sessions, preserves data, and audits reactivation', function (): void {
    ['tenant' => $tenant, 'user' => $tenantUser] = makeTenantWithUser();
    $platformAdmin = createPlatformAdmin(twoFactorEnabled: true);
    $sessionId = (string) Str::uuid();
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $tenantUser, $sessionId): void {
        DB::table('aut_sessions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $tenantUser->getKey(),
            'session_id' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->actingAs($platformAdmin)->post(route('platform-admin.tenants.status.update', ['tenantId' => $tenant->id]), [
        'status' => 'SUSPENDED',
        'reason' => 'Temporary service access pause',
    ])->assertSessionHasNoErrors();

    expect(DB::table('shared_tenants')->where('id', $tenant->id)->value('status'))->toBe('SUSPENDED')
        ->and(DB::table('users')->where('id', $tenantUser->getKey())->exists())->toBeTrue();
    $this->actingAs($tenantUser)->get(route('dashboard'))
        ->assertRedirect(route('login'));

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $tenantUser): void {
        expect(DB::table('aut_sessions')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $tenantUser->getKey())
            ->whereNotNull('revoked_at')
            ->exists())->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('action', 'admin.tenant.suspended')
                ->exists())->toBeTrue();
    });

    session(['platform_access_version' => 0]);
    $this->actingAs($platformAdmin)->post(route('platform-admin.tenants.status.update', ['tenantId' => $tenant->id]), [
        'status' => 'ACTIVE',
        'reason' => 'Service restoration approved',
    ])->assertSessionHasNoErrors();
    expect(DB::table('shared_tenants')->where('id', $tenant->id)->value('status'))->toBe('ACTIVE');

    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $tenantUser): void {
        expect(DB::table('aut_sessions')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $tenantUser->getKey())
            ->whereNotNull('revoked_at')
            ->exists())->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('action', 'admin.tenant.reactivated')
                ->exists())->toBeTrue();
    });
});

it('lets platform Super Admin review safe governance audit events across tenants', function (): void {
    ['tenant' => $tenant, 'user' => $tenantUser] = makeTenantWithUser();
    $platformAdmin = createPlatformAdmin(twoFactorEnabled: true);
    TenantContextScope::run((string) $tenant->id, null, function () use ($tenant, $tenantUser): void {
        DB::table('shared_audit_log')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'actor_id' => $tenantUser->getKey(),
            'action' => 'admin.tenant.suspended',
            'target_type' => 'tenant',
            'target_id' => $tenant->id,
            'metadata' => json_encode([
                'role' => 'tenant_admin',
                'patient_name' => 'Must not be shown',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    });

    $this->actingAs($platformAdmin)
        ->get(route('platform-admin.audit.index', [
            'tenant_id' => $tenant->id,
            'action' => 'admin.tenant.suspended',
        ]))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PlatformAdmin/Audit/Index')
            ->where('total', 1)
            ->where('entries.0.tenantId', (string) $tenant->id)
            ->where('entries.0.tenantName', $tenant->name)
            ->where('entries.0.action', 'admin.tenant.suspended')
            ->where('entries.0.metadata', ['role' => 'tenant_admin']));

    $this->actingAs($platformAdmin)
        ->get(route('platform-admin.audit.index', ['action' => 'platform.audit.viewed']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('entries.0.tenantName', 'Platform')
            ->where('entries.0.action', 'platform.audit.viewed'));
});

it('requires independent approval for Super Admin suspension, reactivation, and MFA recovery', function (): void {
    $requester = createPlatformAdmin(twoFactorEnabled: true);
    $target = createPlatformAdmin(twoFactorEnabled: true);
    $reviewer = createPlatformAdmin(twoFactorEnabled: true);

    $this->actingAs($requester)->post(route('platform-admin.super-admin-access-requests.store'), [
        'target_id' => $requester->getKey(),
        'action' => 'SUSPEND',
        'reason' => 'Self suspension request',
    ])->assertSessionHasErrors('action');

    $this->actingAs($requester)->post(route('platform-admin.super-admin-access-requests.store'), [
        'target_id' => $target->getKey(),
        'action' => 'SUSPEND',
        'reason' => 'Temporary duty separation',
    ])->assertSessionHasNoErrors();
    $suspendRequest = DB::table('platform_super_admin_access_requests')
        ->where('target_user_id', $target->getKey())
        ->where('action', 'SUSPEND')
        ->first();
    expect($suspendRequest)->not->toBeNull();

    $this->actingAs($requester)->post(route('platform-admin.super-admin-access-requests.review', [
        'requestId' => $suspendRequest->id,
    ]), [
        'approve' => true,
        'review_notes' => 'I reviewed my own request',
    ])->assertSessionHasErrors('review_notes');

    $this->actingAs($reviewer)->post(route('platform-admin.super-admin-access-requests.review', [
        'requestId' => $suspendRequest->id,
    ]), [
        'approve' => true,
        'review_notes' => 'Independent review completed',
    ])->assertSessionHasNoErrors();
    expect(DB::table('platform_super_admins')->where('user_id', $target->getKey())->value('status'))->toBe('SUSPENDED')
        ->and((int) DB::table('platform_super_admins')->where('user_id', $target->getKey())->value('access_version'))->toBe(1);

    $this->actingAs($requester)->post(route('platform-admin.super-admin-access-requests.store'), [
        'target_id' => $target->getKey(),
        'action' => 'REACTIVATE',
        'reason' => 'Return to platform operations',
    ])->assertSessionHasNoErrors();
    $reactivateRequestId = DB::table('platform_super_admin_access_requests')
        ->where('target_user_id', $target->getKey())
        ->where('action', 'REACTIVATE')
        ->value('id');
    $this->actingAs($reviewer)->post(route('platform-admin.super-admin-access-requests.review', [
        'requestId' => $reactivateRequestId,
    ]), [
        'approve' => true,
        'review_notes' => 'Reactivation approved',
    ])->assertSessionHasNoErrors();

    $this->actingAs($requester)->post(route('platform-admin.super-admin-access-requests.store'), [
        'target_id' => $target->getKey(),
        'action' => 'RESET_MFA',
        'reason' => 'Administrator lost access to MFA device',
    ])->assertSessionHasNoErrors();
    $mfaRequestId = DB::table('platform_super_admin_access_requests')
        ->where('target_user_id', $target->getKey())
        ->where('action', 'RESET_MFA')
        ->value('id');
    $this->actingAs($reviewer)->post(route('platform-admin.super-admin-access-requests.review', [
        'requestId' => $mfaRequestId,
    ]), [
        'approve' => true,
        'review_notes' => 'Identity verified out of band',
    ])->assertSessionHasNoErrors();

    $target->refresh();
    expect(DB::table('platform_super_admins')->where('user_id', $target->getKey())->value('status'))->toBe('ACTIVE')
        ->and(DB::table('platform_super_admins')->where('user_id', $target->getKey())->value('access_version'))->toBe(3)
        ->and($target->two_factor_secret)->toBeNull()
        ->and($target->two_factor_recovery_codes)->toBeNull()
        ->and($target->two_factor_confirmed_at)->toBeNull();

    $this->actingAs($target)->get(route('platform-admin.index'))
        ->assertRedirect(route('login'));
});

it('lets the platform Super Admin create an isolated tenant with its first organization and facility', function (): void {
    $platformAdmin = createPlatformAdmin(twoFactorEnabled: true);
    DB::table('org_facility_types')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'DISPENSARY',
        'name' => 'Dispensary',
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('org_care_levels')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'PRIMARY',
        'name' => 'Community / Primary',
        'rank' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('org_ownership_types')->insert([
        'id' => (string) Str::uuid7(),
        'code' => 'PUBLIC',
        'name' => 'Public',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($platformAdmin)->post(route('platform-admin.tenants.store'), [
        'tenant_name' => 'DSK Dispensary',
        'tenant_slug' => 'dsk-dispensary-test',
        'organization_name' => 'DSK Dispensary Organization',
        'organization_code' => 'DSK_DISPENSARY',
        'facility_name' => 'DSK Dispensary',
        'facility_code' => 'DSK_DISP',
        'facility_type' => 'DISPENSARY',
        'care_level' => 'PRIMARY',
        'ownership_type' => 'PUBLIC',
    ])->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant = DB::table('shared_tenants')->where('slug', 'dsk-dispensary-test')->first();
    expect($tenant)->not->toBeNull()
        ->and($tenant->status)->toBe('ACTIVE');

    $this->actingAs($platformAdmin)
        ->get(route('platform-admin.tenants.show', ['tenantId' => $tenant->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PlatformAdmin/Tenants/Show')
            ->where('tenant.name', 'DSK Dispensary')
            ->where('tenant.facilities.0.code', 'DSK_DISP')
            ->where('tenant.canInviteAdmin', true));

    TenantContextScope::run($tenant->id, null, function () use ($platformAdmin, $tenant): void {
        $organization = DB::table('org_organizations')->where('tenant_id', $tenant->id)->first();
        $facility = DB::table('org_facilities')->where('tenant_id', $tenant->id)->first();
        $facilityAdminRole = DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('key', FacilityAdminRoleDefinition::KEY)
            ->first();
        $tenantAdminRole = DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('key', TenantAdminRoleDefinition::KEY)
            ->first();
        expect($facilityAdminRole)->not->toBeNull()
            ->and((bool) $facilityAdminRole->is_protected)->toBeTrue()
            ->and($facilityAdminRole->scope_type)->toBe('FACILITY')
            ->and($facilityAdminRole->status)->toBe('ACTIVE')
            ->and($tenantAdminRole)->not->toBeNull()
            ->and((bool) $tenantAdminRole->is_protected)->toBeTrue()
            ->and($tenantAdminRole->scope_type)->toBe('ORG')
            ->and($tenantAdminRole->status)->toBe('ACTIVE');
        $rolePermissions = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $facilityAdminRole->id)
            ->pluck('p.key')
            ->sort()
            ->values()
            ->all();
        expect($rolePermissions)->toEqual(collect(FacilityAdminRoleDefinition::PERMISSIONS)->sort()->values()->all());
        $tenantAdminPermissions = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $tenantAdminRole->id)
            ->pluck('p.key')
            ->sort()
            ->values()
            ->all();
        expect($tenantAdminPermissions)->toEqual(collect(TenantAdminRoleDefinition::PERMISSIONS)->sort()->values()->all());

        expect($organization)->not->toBeNull()
            ->and($organization->status)->toBe('ACTIVE')
            ->and($facility)->not->toBeNull()
            ->and($facility->code)->toBe('DSK_DISP')
            ->and($facility->organization_id)->toBe($organization->id)
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('action', 'organization.organization.created')
                ->exists())->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('target_id', $facility->id)
                ->where('action', 'organization.facility.created')
                ->exists())->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('target_id', $facilityAdminRole->id)
                ->where('action', 'identity.facility_admin.role_provisioned')
                ->exists())->toBeTrue()
            ->and(DB::table('shared_audit_log')
                ->where('tenant_id', $tenant->id)
                ->where('actor_id', $platformAdmin->getKey())
                ->where('target_id', $tenantAdminRole->id)
                ->where('action', 'identity.tenant_admin.role_provisioned')
                ->exists())->toBeTrue()
            ->and(DB::table('users')->where('tenant_id', $tenant->id)->exists())->toBeFalse();
    });
});
