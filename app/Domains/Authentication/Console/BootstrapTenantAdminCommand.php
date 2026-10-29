<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Console;

use App\Domains\Authentication\Mail\AdminBootstrapInvitation;
use App\Domains\Authentication\Support\OneTimeInvitationMailGuard;
use App\Domains\Identity\Application\TenantAdminRoleProvisioner;
use App\Domains\Identity\Contracts\TenantAdminRoleDefinition;
use App\Models\Tenant;
use App\Shared\Audit\AuditLogger;
use App\Shared\Authorization\PermissionRegistry;
use App\Shared\Tenancy\TenantContextScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class BootstrapTenantAdminCommand extends Command
{
    protected $signature = 'afyanova:admin:bootstrap
        {--tenant= : Tenant UUID}
        {--name= : Initial administrator name}
        {--email= : Initial administrator email}
        {--change-ref= : Non-secret operator change or ticket reference}
        {--resend : Rotate the link for the same pending administrator}';

    protected $description = 'Provision or resend the one-time initial Tenant Admin invitation.';

    public function handle(AuditLogger $audit): int
    {
        $tenantId = trim((string) $this->option('tenant'));
        $name = trim((string) $this->option('name'));
        $email = Str::lower(trim((string) $this->option('email')));
        $changeReference = trim((string) $this->option('change-ref'));
        $resend = (bool) $this->option('resend');

        if ($tenantId === '' || $email === '' || $changeReference === '' || (! $resend && $name === '')) {
            $this->error('--tenant, --email, and --change-ref are required; --name is required for initial provisioning.');

            return self::FAILURE;
        }

        if (! Str::isUuid($tenantId)
            || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($name) > 120
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/', $changeReference)) {
            $this->error('Tenant, administrator details, or change reference are invalid.');

            return self::FAILURE;
        }

        if (! OneTimeInvitationMailGuard::canDeliver()) {
            $this->error('The configured mail transport cannot deliver setup links without writing their secret URL to logs. Configure a delivery mailer before bootstrapping.');

            return self::FAILURE;
        }

        foreach (TenantAdminRoleDefinition::PERMISSIONS as $permission) {
            if (! PermissionRegistry::has($permission)) {
                $this->error("Required permission '{$permission}' is not registered.");

                return self::FAILURE;
            }
        }

        if (! Tenant::query()->whereKey($tenantId)->exists()) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }

        $result = TenantContextScope::run($tenantId, null, function () use (
            $tenantId,
            $name,
            $email,
            $changeReference,
            $resend,
            $audit,
        ): array {
            $tenant = DB::table('shared_tenants')->where('id', $tenantId)->lockForUpdate()->first();
            if ($tenant === null || $tenant->status !== 'ACTIVE') {
                return ['error' => 'An active tenant is required.'];
            }

            $organizations = DB::table('org_organizations')
                ->where('tenant_id', $tenantId)
                ->get(['id', 'status']);
            $organization = $organizations->first();
            if ($organizations->count() !== 1 || $organization === null || $organization->status !== 'ACTIVE') {
                return ['error' => 'Bootstrap requires exactly one active organization for the tenant.'];
            }

            $activeAdminExists = DB::table('idn_access_assignments as aa')
                ->join('idn_roles as r', 'r.id', '=', 'aa.role_id')
                ->where('aa.tenant_id', $tenantId)
                ->where('r.key', TenantAdminRoleDefinition::KEY)
                ->where('r.status', 'ACTIVE')
                ->where('aa.status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('aa.effective_from')->orWhere('aa.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('aa.effective_to')->orWhere('aa.effective_to', '>=', now()))
                ->exists();
            if ($activeAdminExists) {
                return ['error' => 'An effective Tenant Admin already exists.'];
            }

            $pendingInvites = DB::table('aut_admin_bootstrap_tokens as t')
                ->join('users as u', 'u.id', '=', 't.user_id')
                ->where('t.tenant_id', $tenantId)
                ->where('t.purpose', 'TENANT_ADMIN')
                ->whereNull('t.facility_id')
                ->whereNull('t.consumed_at')
                ->lockForUpdate()
                ->get(['t.id', 't.user_id', 't.expires_at', 'u.email', 'u.email_verified_at', 'u.status']);
            if ($pendingInvites->count() > 1) {
                return ['error' => 'Multiple pending initial-admin invitations require operator review.'];
            }
            $pendingInvite = $pendingInvites->first();
            if ($pendingInvite !== null) {
                if ($pendingInvite->email !== $email) {
                    return ['error' => 'A pending initial-admin invitation already exists for this tenant.'];
                }
                if (! $resend) {
                    return ['error' => 'A pending initial-admin invitation exists; use --resend for the same email to rotate its link.'];
                }
                if ($pendingInvite->email_verified_at !== null || $pendingInvite->status !== 'ACTIVE') {
                    return ['error' => 'The existing invitation is not eligible for resending.'];
                }
            } elseif ($resend) {
                return ['error' => 'No pending initial-admin invitation exists for that email.'];
            }

            if ($pendingInvite === null && DB::table('users')->where('email', $email)->exists()) {
                return ['error' => 'That email already belongs to an account.'];
            }

            try {
                $roleId = app(TenantAdminRoleProvisioner::class)->provision(
                    tenantId: $tenantId,
                    actorId: null,
                    reason: 'Provisioned during initial Tenant Admin bootstrap.',
                );
            } catch (\RuntimeException $exception) {
                return ['error' => $exception->getMessage()];
            }

            $userId = $pendingInvite->user_id ?? (string) Str::uuid7();
            $token = Str::random(64);

            if ($pendingInvite === null) {
                DB::table('users')->insert([
                    'id' => $userId,
                    'tenant_id' => $tenantId,
                    'name' => $name,
                    'email' => $email,
                    'password' => Hash::make(Str::random(80)),
                    'status' => 'ACTIVE',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('idn_access_assignments')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'organization_id' => (string) $organization->id,
                    'status' => 'PENDING',
                    'is_primary' => true,
                    'version' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $pendingAssignments = DB::table('idn_access_assignments')
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->where('role_id', $roleId)
                    ->where('status', 'PENDING')
                    ->whereNull('facility_id')
                    ->where('organization_id', $organization->id)
                    ->count();
                if ($pendingAssignments > 1) {
                    return ['error' => 'The pending invitation has duplicate Tenant Admin assignments requiring operator review.'];
                }
                if ($pendingAssignments === 0) {
                    DB::table('idn_access_assignments')->insert([
                        'id' => (string) Str::uuid7(),
                        'tenant_id' => $tenantId,
                        'user_id' => $userId,
                        'role_id' => $roleId,
                        'organization_id' => (string) $organization->id,
                        'status' => 'PENDING',
                        'is_primary' => true,
                        'version' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $tokenHash = hash('sha256', $token);
            $expiresAt = now()->addMinutes(60);
            if ($pendingInvite === null) {
                DB::table('aut_admin_bootstrap_tokens')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'token_hash' => $tokenHash,
                    'created_by_reference' => $changeReference,
                    'expires_at' => $expiresAt,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('aut_admin_bootstrap_tokens')
                    ->where('id', $pendingInvite->id)
                    ->update([
                        'token_hash' => $tokenHash,
                        'created_by_reference' => $changeReference,
                        'expires_at' => $expiresAt,
                        'updated_at' => now(),
                    ]);
            }

            $audit->recordFor(
                tenantId: $tenantId,
                action: $pendingInvite === null ? 'admin.tenant_admin.bootstrap_invited' : 'admin.tenant_admin.bootstrap_invitation_resent',
                targetType: 'user',
                targetId: $userId,
                reason: 'Operator provisioning reference: '.$changeReference,
                metadata: ['provisioning_reference' => $changeReference],
            );

            return ['email' => $email, 'token' => $token];
        });

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        Mail::to($result['email'])->send(new AdminBootstrapInvitation($result['token']));
        $this->info('The initial Tenant Admin setup invitation was sent. The setup link expires in 60 minutes.');

        return self::SUCCESS;
    }
}
