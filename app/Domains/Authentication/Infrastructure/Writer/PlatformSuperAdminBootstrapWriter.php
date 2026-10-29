<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Infrastructure\Writer;

use App\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class PlatformSuperAdminBootstrapWriter
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function complete(string $tokenHash, string $password): void
    {
        DB::transaction(function () use ($tokenHash, $password): void {
            $invitation = DB::table('platform_super_admin_bootstrap_tokens')
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();
            abort_unless(
                $invitation !== null
                    && $invitation->consumed_at === null
                    && now()->lt($invitation->expires_at),
                403,
                'This platform administrator setup link is invalid or has expired.',
            );

            $platformAdmin = DB::table('platform_super_admins')
                ->where('user_id', $invitation->user_id)
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            $user = User::query()
                ->whereKey($invitation->user_id)
                ->whereNull('tenant_id')
                ->where('status', 'PENDING')
                ->lockForUpdate()
                ->first();
            abort_unless($platformAdmin !== null && $user instanceof User && $user->email_verified_at === null, 403);

            abort_if(
                DB::table('platform_super_admins')->where('status', 'ACTIVE')->exists(),
                409,
                'An active platform Super Admin already exists.',
            );

            DB::table('platform_super_admins')
                ->where('id', $platformAdmin->id)
                ->where('status', 'PENDING')
                ->update(['status' => 'ACTIVE', 'activated_at' => now(), 'updated_at' => now()]);

            $user->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'status' => 'ACTIVE',
            ])->save();

            DB::table('platform_super_admin_bootstrap_tokens')
                ->where('id', $invitation->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now(), 'updated_at' => now()]);

            $this->audit->recordPlatform(
                action: 'platform.super_admin.bootstrap_completed',
                actorId: (string) $user->getKey(),
                targetType: 'platform_super_admin',
                targetId: (string) $platformAdmin->id,
                reason: 'Operator provisioning reference: '.$invitation->created_by_reference,
                metadata: ['provisioning_reference' => $invitation->created_by_reference],
            );
        });
    }
}
