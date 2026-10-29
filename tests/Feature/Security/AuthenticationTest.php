<?php

declare(strict_types=1);

use App\Models\User;
use App\Shared\Tenancy\Tenancy;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Tests\TestCase;

/*
 | PHASE 2 gate — authentication & session security
 | (docs/00-foundation/04-authentication.md §13–§20, §54).
 */

function makeLoginUser(array $overrides = []): User
{
    ['user' => $user] = makeTenantWithUser();

    $user->forceFill(['password' => Hash::make('correct-horse'), ...$overrides])->save();

    return $user->refresh();
}

function signInAndCarrySessionCookie(TestCase $test, User $user): string
{
    $response = $test->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
    $response->assertRedirect('/dashboard');

    $cookieName = (string) config('session.cookie');
    $cookie = $response->getCookie($cookieName);
    if ($cookie === null) {
        throw new RuntimeException('Successful login did not issue a session cookie.');
    }

    $test->withCookie($cookieName, $cookie->getValue());

    return $cookie->getValue();
}

it('signs in a valid, verified, active user and lands them on the dashboard', function (): void {
    $user = makeLoginUser();

    signInAndCarrySessionCookie($this, $user);
    $this->assertAuthenticatedAs($user);

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));

    // A subsequent full-page request keeps the server-side session authenticated.
    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard/Index'));
});

it('invalidates the authenticated Laravel session when its tracked device session is revoked', function (): void {
    $user = makeLoginUser();

    $sessionId = signInAndCarrySessionCookie($this, $user);
    TenantContextScope::run((string) $user->tenant_id, null, function () use ($user, $sessionId): void {
        expect(DB::table('aut_sessions')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->exists())->toBeTrue();

        DB::table('aut_sessions')
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->getKey())
            ->where('session_id', $sessionId)
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    });

    $this->get('/dashboard')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('uses the dashboard as the authenticated home destination without requiring workspace permission', function (): void {
    $user = makeLoginUser();

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect('/dashboard');
});

it('lands an HR user on the dashboard instead of a stale unauthorized intended destination', function (): void {
    ['user' => $user] = makeTenantWithUser(['hr.staff.read']);
    $user->forceFill(['password' => Hash::make('correct-horse')])->save();

    $this->get('/workspaces/hr/staff/positions')
        ->assertRedirect('/login');

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/dashboard')
        ->assertSessionMissing('url.intended');
});

it('rejects a wrong password with a generic error and no session', function (): void {
    $user = makeLoginUser();

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('keeps a user with only pending admin approval out of the application and explains why', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'organization' => $organization, 'user' => $user] = makeTenantWithUser();
    $user->forceFill(['password' => Hash::make('correct-horse')])->save();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $facility, $organization, $user): void {
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->first();
        expect($assignment)->not->toBeNull();
        DB::table('idn_access_assignments')->where('id', $assignment->id)->update(['status' => 'REVOKED']);
        DB::table('idn_admin_role_promotion_requests')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'target_user_id' => $user->getKey(),
            'role_id' => $assignment->role_id,
            'role_key' => 'facility_admin',
            'scope_type' => 'FACILITY',
            'action' => 'PROMOTE',
            'status' => 'PENDING',
            'reason' => 'Provide facility administration.',
            'requested_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors([
            'email' => 'Your Facility Admin access request is awaiting approval by an authorized administrator. You can sign in after it is approved. Please contact that administrator for help.',
        ]);

    $this->assertGuest();
    $this->actingAs($user)
        ->get('/dashboard')
        ->assertRedirect('/login')
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    expect(DB::table('aut_auth_events')
        ->where('user_id', $user->getKey())
        ->where('event', 'login_failed')
        ->whereJsonContains('metadata->reason', 'administrative_access_pending')
        ->exists())->toBeTrue();
});

it('explains pending operational staff access at sign-in', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'organization' => $organization, 'user' => $user] = makeTenantWithUser();
    $user->forceFill(['password' => Hash::make('correct-horse')])->save();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $facility, $organization, $user): void {
        $assignment = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $user->getKey())
            ->first();
        expect($assignment)->not->toBeNull();
        DB::table('idn_access_assignments')->where('id', $assignment->id)->update(['status' => 'REVOKED']);
        DB::table('idn_admin_role_promotion_requests')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'target_user_id' => $user->getKey(),
            'role_id' => $assignment->role_id,
            'role_key' => 'nurse',
            'scope_type' => 'FACILITY',
            'action' => 'PROMOTE',
            'status' => 'PENDING',
            'reason' => 'Provide nursing care.',
            'requested_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->from('/login')
        ->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors([
            'email' => 'Your Nurse access request is awaiting approval by an authorized administrator. You can sign in after it is approved. Please contact that administrator for help.',
        ]);

    $this->assertGuest();
});

it('allows normal sign-in when the user already has active access while another admin request is pending', function (): void {
    ['tenant' => $tenant, 'facility' => $facility, 'organization' => $organization, 'user' => $user, 'role_id' => $roleId] = makeTenantWithUser();
    $user->forceFill(['password' => Hash::make('correct-horse')])->save();

    TenantContextScope::run($tenant->id, null, function () use ($tenant, $facility, $organization, $user, $roleId): void {
        DB::table('idn_admin_role_promotion_requests')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenant->id,
            'organization_id' => $organization->id,
            'facility_id' => $facility->id,
            'target_user_id' => $user->getKey(),
            'role_id' => $roleId,
            'role_key' => 'facility_admin',
            'scope_type' => 'FACILITY',
            'action' => 'PROMOTE',
            'status' => 'PENDING',
            'reason' => 'Provide additional facility administration.',
            'requested_by' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
});

it('does not reveal whether an email exists', function (): void {
    $this->from('/login')
        ->post('/login', ['email' => 'nobody@nowhere.test', 'password' => 'whatever'])
        ->assertSessionHasErrors('email');

    // same error key/shape as a wrong password (checked above) — no enumeration.
    $this->assertGuest();
});

it('refuses a suspended account', function (): void {
    $user = makeLoginUser(['status' => 'SUSPENDED']);

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('keeps an unverified user away from protected routes until they verify', function (): void {
    $user = makeLoginUser(['email_verified_at' => null]);

    signInAndCarrySessionCookie($this, $user);
    $this->assertAuthenticatedAs($user);

    $this->get('/dashboard')->assertRedirect('/email/verify');
});

it('regenerates the session id on login (fixation defence)', function (): void {
    $user = makeLoginUser();

    $this->get('/login');
    $before = session()->getId();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);

    expect(session()->getId())->not->toBe($before);
});

it('records an auth event and a device session on login', function (): void {
    $user = makeLoginUser();

    $this->post('/login', ['email' => $user->email, 'password' => 'correct-horse']);
    $sessionId = session()->getId();

    expect(DB::table('aut_auth_events')->where('user_id', $user->id)->where('event', 'login')->exists())->toBeTrue();

    Tenancy::run($user->tenant_id, function () use ($user, $sessionId): void {
        expect(DB::table('aut_sessions')
            ->where('user_id', $user->id)
            ->where('session_id', $sessionId)
            ->whereNull('revoked_at')
            ->exists())->toBeTrue();
    });
});

it('audits recovery-code use without storing the recovery code', function (): void {
    $user = makeLoginUser();
    $recoveryCode = 'sensitive-one-time-recovery-code';

    event(new RecoveryCodeReplaced($user, $recoveryCode));

    $audit = DB::table('shared_audit_log')
        ->where('tenant_id', $user->tenant_id)
        ->where('action', 'auth.credential.mfa_recovery_used')
        ->first();
    $telemetry = DB::table('aut_auth_events')
        ->where('tenant_id', $user->tenant_id)
        ->where('event', 'mfa_recovery_used')
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->actor_id)->toBe($user->id)
        ->and($audit->target_type)->toBe('user')
        ->and($audit->target_id)->toBe($user->id)
        ->and($audit->metadata)->not->toContain($recoveryCode)
        ->and($telemetry)->not->toBeNull()
        ->and($telemetry->metadata)->not->toContain($recoveryCode);
});

it('logs out and invalidates the session', function (): void {
    $user = makeLoginUser();
    $this->actingAs($user);

    $this->post('/logout')->assertRedirect('/login');
    $this->assertGuest();

    $this->get('/dashboard')->assertRedirect('/login');
});
