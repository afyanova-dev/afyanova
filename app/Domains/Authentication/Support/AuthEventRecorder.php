<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Support;

use App\Models\User;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\Tenancy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Authentication telemetry (docs/00-foundation/04-authentication.md §37–§38) and
 * device / session tracking (§22–§23).
 *
 * `aut_auth_events` is the raw stream (may have no tenant — a failed login).
 * `aut_sessions` is the per-login device record (tenant-scoped). Security-
 * relevant events (credential / MFA changes) are additionally written to
 * `shared_audit_log` via {@see AuditLogger} (IC §18 mandatory list).
 *
 * Auth events never carry secrets (invariant 7) or PHI (`AGENTS.md §38`).
 */
final class AuthEventRecorder
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function onLogin(User $user, Request $request): void
    {
        $tenantId = $user->tenant_id;
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $this->event('login', $user, $request, ['session_id' => $sessionId]);
        if ($tenantId === null && $request->hasSession()) {
            $accessVersion = DB::table('platform_super_admins')
                ->where('user_id', $user->getKey())
                ->where('status', 'ACTIVE')
                ->value('access_version');
            if ($accessVersion !== null) {
                $request->session()->put('platform_access_version', (int) $accessVersion);
            }
        }
        if ($tenantId !== null && $sessionId !== null) {
            $request->session()->put('aut_session_tracking_pending', true);
        }
    }

    public function trackCurrentSession(User $user, Request $request): void
    {
        $tenantId = $user->tenant_id;
        if ($tenantId === null || ! $request->hasSession()) {
            return;
        }

        $sessionId = $request->session()->getId();
        Tenancy::run($tenantId, function () use ($user, $request, $sessionId, $tenantId): void {
            DB::table('aut_sessions')->updateOrInsert(
                ['user_id' => $user->getKey(), 'session_id' => $sessionId],
                [
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'ip_address' => $request->ip(),
                    'user_agent' => Str::limit((string) $request->userAgent(), 500, ''),
                    'last_active_at' => now(),
                    'revoked_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        });

        $request->session()->put('aut_session_tracked', true);
        $request->session()->forget('aut_session_tracking_pending');
    }

    public function onLogout(User $user, Request $request): void
    {
        $tenantId = $user->tenant_id;
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;

        $this->event('logout', $user, $request, ['session_id' => $sessionId]);

        if ($tenantId !== null && $sessionId !== null) {
            Tenancy::run($tenantId, static function () use ($user, $sessionId): void {
                DB::table('aut_sessions')
                    ->where('user_id', $user->getKey())
                    ->where('session_id', $sessionId)
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            });
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(string $event, ?User $user, array $metadata = []): void
    {
        $this->event($event, $user, request(), $metadata);
    }

    /**
     * A security-relevant event: telemetry + a `shared_audit_log` row.
     */
    public function audited(string $event, User $user, string $auditAction): void
    {
        $this->event($event, $user, request());

        if ($user->tenant_id === null) {
            $this->audit->recordPlatform(
                action: $auditAction,
                actorId: (string) $user->getKey(),
                targetType: 'user',
                targetId: (string) $user->getKey(),
            );

            return;
        }

        Tenancy::run((string) $user->tenant_id, fn () => $this->audit->recordFor(
            tenantId: (string) $user->tenant_id,
            action: $auditAction,
            actorId: (string) $user->getKey(),
            targetType: 'user',
            targetId: (string) $user->getKey(),
        ));
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function event(string $event, ?User $user, ?Request $request, array $metadata = []): void
    {
        DB::table('aut_auth_events')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->getKey(),
            'event' => $event,
            'ip_address' => $request?->ip(),
            'metadata' => json_encode(array_filter($metadata, static fn ($v) => $v !== null), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
