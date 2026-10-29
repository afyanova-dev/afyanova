<?php

declare(strict_types=1);

namespace App\Domains\Authentication;

use App\Domains\Authentication\Application\AccountDirectoryApiService;
use App\Domains\Authentication\Application\Fortify\DashboardLoginResponse;
use App\Domains\Authentication\Application\Fortify\DashboardTwoFactorLoginResponse;
use App\Domains\Authentication\Application\Fortify\ResetUserPassword;
use App\Domains\Authentication\Application\Fortify\UpdateUserPassword;
use App\Domains\Authentication\Application\PendingAdministrativeAccess;
use App\Domains\Authentication\Application\PlatformAdministrationService;
use App\Domains\Authentication\Console\BootstrapPlatformSuperAdminCommand;
use App\Domains\Authentication\Console\BootstrapTenantAdminCommand;
use App\Domains\Authentication\Console\ExpireFacilityInvitationsCommand;
use App\Domains\Authentication\Contracts\AccountDirectoryApi;
use App\Domains\Authentication\Contracts\FacilityUserInvitationApi;
use App\Domains\Authentication\Support\AuthEventRecorder;
use App\Domains\Identity\Contracts\LinkedStaffAccessGate;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Events\RecoveryCodeReplaced;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

/**
 * Authentication module (docs/00-foundation/04-authentication.md, ADR-113),
 * prefix `aut_`. Wraps Laravel Fortify (headless — Inertia view callbacks, no
 * Blade) and adds AfyaNova's status gate, session/device telemetry
 * (`aut_sessions`), and auth-event audit (`aut_auth_events` + `AuditLogger`).
 */
final class AuthenticationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AccountDirectoryApi::class, AccountDirectoryApiService::class);
        $this->app->bind(FacilityUserInvitationApi::class, PlatformAdministrationService::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/routes.php');

        if ($this->app->runningInConsole()) {
            $this->commands([
                BootstrapPlatformSuperAdminCommand::class,
                BootstrapTenantAdminCommand::class,
                ExpireFacilityInvitationsCommand::class,
            ]);
        }

        $this->configureFortifyActions();
        $this->configureFortifyResponses();
        $this->configureFortifyViews();
        $this->configureRateLimiters();
        $this->configureAuthenticationGate();
        $this->configureAuthEventTelemetry();
    }

    private function configureFortifyActions(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
    }

    private function configureFortifyResponses(): void
    {
        $this->app->singleton(LoginResponse::class, DashboardLoginResponse::class);
        $this->app->singleton(TwoFactorLoginResponse::class, DashboardTwoFactorLoginResponse::class);
    }

    private function configureFortifyViews(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login', [
            'canResetPassword' => true,
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('Auth/TwoFactorChallenge'));

        Fortify::requestPasswordResetLinkView(fn () => Inertia::render('Auth/ForgotPassword'));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('Auth/ResetPassword', [
            'email' => $request->string('email')->value(),
            'token' => $request->route('token'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('Auth/ConfirmPassword'));

        Fortify::verifyEmailView(fn () => Inertia::render('Auth/VerifyEmail'));
    }

    private function configureRateLimiters(): void
    {
        // docs 04 §14 — per email + IP.
        RateLimiter::for('login', function (Request $request): Limit {
            $key = Str::transliterate(Str::lower($request->input(Fortify::username()).'|'.$request->ip()));

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request): Limit => Limit::perMinute(5)->by($request->session()->get('login.id')));
    }

    /**
     * The authoritative credential check plus AfyaNova's account gates
     * (docs 04 §54 invariants 3 & 5): a SUSPENDED / DEACTIVATED or unverified
     * account cannot create an operational session. Generic failure message —
     * no account enumeration (invariant 12).
     */
    private function configureAuthenticationGate(): void
    {
        Fortify::authenticateUsing(function (Request $request): User {
            $user = User::query()->where('email', Str::lower((string) $request->input(Fortify::username())))->first();

            $generic = ValidationException::withMessages([
                Fortify::username() => [__('auth.failed')],
            ]);

            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                throw $generic;
            }

            if (! $user->isActive()) {
                app(AuthEventRecorder::class)->record('login_failed', $user, ['reason' => 'inactive']);
                throw $generic;
            }

            if ($user->tenant_id !== null
                && app(LinkedStaffAccessGate::class)->isBlockedByHrLifecycle((string) $user->tenant_id, (string) $user->getKey())) {
                app(AuthEventRecorder::class)->record('login_failed', $user, ['reason' => 'hr_lifecycle_block']);
                throw $generic;
            }

            $pendingAccessMessage = app(PendingAdministrativeAccess::class)->messageFor($user);
            if ($pendingAccessMessage !== null) {
                app(AuthEventRecorder::class)->record('login_failed', $user, [
                    'reason' => 'administrative_access_pending',
                ]);
                throw ValidationException::withMessages([
                    Fortify::username() => [$pendingAccessMessage],
                ]);
            }

            return $user;
        });
    }

    private function configureAuthEventTelemetry(): void
    {
        Event::listen(Login::class, function (Login $e): void {
            /** @var User $user */
            $user = $e->user;
            app(AuthEventRecorder::class)->onLogin($user, request());
        });

        Event::listen(Failed::class, function (Failed $e): void {
            $creds = $e->credentials;
            app(AuthEventRecorder::class)->record('login_failed', $e->user instanceof User ? $e->user : null, [
                'email' => is_string($creds['email'] ?? null) ? $creds['email'] : null,
            ]);
        });

        Event::listen(Logout::class, function (Logout $e): void {
            if ($e->user instanceof User) {
                app(AuthEventRecorder::class)->onLogout($e->user, request());
            }
        });

        Event::listen(Lockout::class, fn (Lockout $e) => app(AuthEventRecorder::class)->record('locked', null, [
            'email' => $e->request->input(Fortify::username()),
        ]));

        Event::listen(PasswordReset::class, function (PasswordReset $e): void {
            if ($e->user instanceof User) {
                app(AuthEventRecorder::class)->audited('password_reset', $e->user, 'auth.credential.password_reset');
            }
        });

        Event::listen(TwoFactorAuthenticationConfirmed::class, function (TwoFactorAuthenticationConfirmed $e): void {
            app(AuthEventRecorder::class)->audited('mfa_enabled', $e->user, 'auth.credential.mfa_enabled');
        });

        Event::listen(TwoFactorAuthenticationDisabled::class, function (TwoFactorAuthenticationDisabled $e): void {
            app(AuthEventRecorder::class)->audited('mfa_reset', $e->user, 'auth.credential.mfa_disabled');
        });

        Event::listen(RecoveryCodeReplaced::class, function (RecoveryCodeReplaced $e): void {
            if ($e->user instanceof User) {
                app(AuthEventRecorder::class)->audited('mfa_recovery_used', $e->user, 'auth.credential.mfa_recovery_used');
            }
        });
    }
}
