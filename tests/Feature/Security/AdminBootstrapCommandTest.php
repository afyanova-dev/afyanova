<?php

declare(strict_types=1);

use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Models\User;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

function initialAdminBootstrapOptions(string $tenantId, string $email = 'tenant-admin@example.test'): array
{
    return [
        '--tenant' => $tenantId,
        '--name' => 'Initial Administrator',
        '--email' => $email,
        '--change-ref' => 'OPS-CHANGE-2026-104',
    ];
}

function initialAdminSetupToken(): string
{
    $mail = Mail::sent(AdminBootstrapInvitation::class)->last();
    expect($mail)->toBeInstanceOf(AdminBootstrapInvitation::class);
    preg_match('/#([A-Za-z0-9]{64})/', $mail->render(), $matches);

    expect($matches[1] ?? null)->toBeString();

    return $matches[1];
}

it('provisions a protected pending Tenant Admin and completes the one-time setup link', function (): void {
    ['tenant' => $tenant, 'organization' => $organization] = makeTenantWithUser();
    Mail::fake();

    expect(Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id)))->toBe(0);
    $commandOutput = Artisan::output();
    Mail::assertSent(AdminBootstrapInvitation::class, fn (AdminBootstrapInvitation $mail): bool => $mail->hasTo('tenant-admin@example.test'));

    $token = initialAdminSetupToken();
    expect($commandOutput)->not->toContain($token);

    $user = DB::table('users')->where('email', 'tenant-admin@example.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->toBeNull()
        ->and(Hash::check('some random setup password', $user->password))->toBeFalse();

    $storedToken = DB::table('aut_admin_bootstrap_tokens')->where('user_id', $user->id)->first();
    expect($storedToken->token_hash)->toBe(hash('sha256', $token))
        ->and($storedToken->consumed_at)->toBeNull()
        ->and($storedToken->expires_at)->not->toBeNull();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $user): void {
        $role = DB::table('idn_roles')->where('tenant_id', $tenant->id)->where('key', 'tenant_admin')->first();
        expect($role->is_protected)->toBeTrue()
            ->and($role->scope_type)->toBe('ORG');

        $permissionKeys = DB::table('idn_permission_role as pr')
            ->join('idn_permissions as p', 'p.id', '=', 'pr.permission_id')
            ->where('pr.role_id', $role->id)
            ->pluck('p.key')
            ->sort()
            ->values()
            ->all();
        expect($permissionKeys)->toBe(collect(TenantAdminRoleDefinition::PERMISSIONS)->sort()->values()->all());

        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('role_id', $role->id)
            ->first();
        expect($assignment->status)->toBe('PENDING')
            ->and($assignment->organization_id)->toBe($organization->id)
            ->and($assignment->facility_id)->toBeNull();
    });

    it('rejects an expired bootstrap link without activating the assignment', function (): void {
        ['tenant' => $tenant] = makeTenantWithUser();
        Mail::fake();
        Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id));

        $token = initialAdminSetupToken();
        DB::table('aut_admin_bootstrap_tokens')->update(['expires_at' => now()->subMinute()]);

        $this->post(route('admin.bootstrap.complete'), [
            'token' => $token,
            'password' => 'LongTestPassword!2026',
            'password_confirmation' => 'LongTestPassword!2026',
        ])->assertSessionHasErrors([
            'token' => 'This setup link is invalid or has expired. Ask the administrator who invited you to send a new invitation.',
        ]);

        $user = DB::table('users')->where('email', 'tenant-admin@example.test')->first();
        expect($user->email_verified_at)->toBeNull();
        TenantContextScope::run($tenant->id, null, function () use ($tenant, $user): void {
            expect(DB::table('idn_access_assignments')
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $user->id)
                ->value('status'))->toBe('PENDING');
        });
    });

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongTestPassword!2026',
        'password_confirmation' => 'LongTestPassword!2026',
    ])->assertRedirect(route('login'));

    $user = DB::table('users')->where('email', 'tenant-admin@example.test')->first();
    expect($user->email_verified_at)->not->toBeNull()
        ->and(Hash::check('LongTestPassword!2026', $user->password))->toBeTrue();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $user): void {
        expect(DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'ACTIVE')
            ->exists())->toBeTrue();

        expect(DB::table('shared_audit_log')
            ->where('tenant_id', $tenant->id)
            ->where('target_id', $user->id)
            ->where('action', 'admin.tenant_admin.bootstrap_completed')
            ->whereNull('actor_id')
            ->exists())->toBeTrue();
    });

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'AnotherTestPassword!2026',
        'password_confirmation' => 'AnotherTestPassword!2026',
    ])->assertSessionHasErrors('token');
});

it('refuses a second pending invitation for a different administrator', function (): void {
    ['tenant' => $tenant] = makeTenantWithUser();
    Mail::fake();

    expect(Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id)))->toBe(0);

    $options = initialAdminBootstrapOptions($tenant->id, 'second-admin@example.test');
    expect(Artisan::call('afyanova:admin:bootstrap', $options))->toBe(1)
        ->and(Artisan::output())->toContain('A pending initial-admin invitation already exists for this tenant.');
    Mail::assertSent(AdminBootstrapInvitation::class, 1);
});

it('repairs a legacy pending invitation with no Tenant Admin role or assignment when resent', function (): void {
    ['tenant' => $tenant, 'organization' => $organization] = makeTenantWithUser();
    $pendingUser = User::factory()->forTenant($tenant->id)->create([
        'email' => 'tenant-admin@example.test',
        'email_verified_at' => null,
        'status' => 'ACTIVE',
    ]);
    TenantContextScope::run($tenant->id, null, function () use ($tenant, $pendingUser): void {
        DB::table('aut_admin_bootstrap_tokens')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'user_id' => $pendingUser->getKey(),
            'token_hash' => hash('sha256', 'legacy-pending-token'),
            'created_by_reference' => 'OPS-CHANGE-2026-104',
            'purpose' => 'TENANT_ADMIN',
            'expires_at' => now()->addMinutes(60),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });
    Mail::fake();

    $options = initialAdminBootstrapOptions($tenant->id);
    $options['--resend'] = true;
    expect(Artisan::call('afyanova:admin:bootstrap', $options))->toBe(0);
    Mail::assertSent(AdminBootstrapInvitation::class, fn ($mail): bool => $mail->hasTo('tenant-admin@example.test'));

    $token = initialAdminSetupToken();
    TenantContextScope::run($tenant->id, null, function () use ($tenant, $organization, $pendingUser): void {
        $role = DB::table('idn_roles')
            ->where('tenant_id', $tenant->id)
            ->where('key', TenantAdminRoleDefinition::KEY)
            ->first();
        expect($role)->not->toBeNull()
            ->and((bool) $role->is_protected)->toBeTrue()
            ->and($role->scope_type)->toBe('ORG');
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $pendingUser->getKey())
            ->where('role_id', $role->id)
            ->first();
        expect($assignment)->not->toBeNull()
            ->and($assignment->status)->toBe('PENDING')
            ->and($assignment->organization_id)->toBe($organization->id);
    });

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongTestPassword!2026',
        'password_confirmation' => 'LongTestPassword!2026',
    ])->assertRedirect(route('login'));
    expect(DB::table('users')->where('id', $pendingUser->getKey())->value('email_verified_at'))->not->toBeNull();
});

it('rotates a pending invitation only for the same administrator and invalidates the earlier link', function (): void {
    ['tenant' => $tenant] = makeTenantWithUser();
    Mail::fake();
    Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id));
    $firstToken = initialAdminSetupToken();

    $resend = initialAdminBootstrapOptions($tenant->id);
    $resend['--resend'] = true;
    expect(Artisan::call('afyanova:admin:bootstrap', $resend))->toBe(0);
    $secondToken = initialAdminSetupToken();
    expect($secondToken)->not->toBe($firstToken);
    Mail::assertSent(AdminBootstrapInvitation::class, 2);

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $firstToken,
        'password' => 'LongTestPassword!2026',
        'password_confirmation' => 'LongTestPassword!2026',
    ])->assertSessionHasErrors('token');

    $this->post(route('admin.bootstrap.complete'), [
        'token' => $secondToken,
        'password' => 'LongTestPassword!2026',
        'password_confirmation' => 'LongTestPassword!2026',
    ])->assertRedirect(route('login'));
});

it('refuses bootstrap when the tenant does not have exactly one organization', function (): void {
    ['tenant' => $tenant, 'organization' => $organization] = makeTenantWithUser();
    TenantContextScope::run($tenant->id, null, fn () => $organization->update(['status' => 'SUSPENDED']));
    Mail::fake();

    expect(Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id)))->toBe(1)
        ->and(Artisan::output())->toContain('Bootstrap requires exactly one active organization for the tenant.');
    Mail::assertNothingSent();
});

it('refuses bootstrap after an effective Tenant Admin exists', function (): void {
    ['tenant' => $tenant] = makeTenantWithUser();
    Mail::fake();
    Artisan::call('afyanova:admin:bootstrap', initialAdminBootstrapOptions($tenant->id));

    $token = initialAdminSetupToken();
    $this->post(route('admin.bootstrap.complete'), [
        'token' => $token,
        'password' => 'LongTestPassword!2026',
        'password_confirmation' => 'LongTestPassword!2026',
    ])->assertRedirect(route('login'));

    $options = initialAdminBootstrapOptions($tenant->id, 'second-admin@example.test');
    expect(Artisan::call('afyanova:admin:bootstrap', $options))->toBe(1)
        ->and(Artisan::output())->toContain('An effective Tenant Admin already exists.');
    Mail::assertSent(AdminBootstrapInvitation::class, 1);
});
