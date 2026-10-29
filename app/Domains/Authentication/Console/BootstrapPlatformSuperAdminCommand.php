<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Console;

use App\Domains\Authentication\Mail\PlatformSuperAdminInvitation;
use App\Domains\Authentication\Support\OneTimeInvitationMailGuard;
use App\Shared\Audit\AuditLogger;
use App\Shared\Tenancy\SystemContext;
use App\Shared\Tenancy\SystemOperation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

final class BootstrapPlatformSuperAdminCommand extends Command
{
    protected $signature = 'afyanova:platform-admin:bootstrap
        {--name= : Initial platform administrator name}
        {--email= : Initial platform administrator email}
        {--change-ref= : Non-secret operator change or ticket reference}
        {--resend : Rotate the link for the same pending administrator}';

    protected $description = 'Provision or resend the one-time initial platform Super Admin invitation.';

    public function handle(AuditLogger $audit): int
    {
        $name = trim((string) $this->option('name'));
        $email = Str::lower(trim((string) $this->option('email')));
        $changeReference = trim((string) $this->option('change-ref'));
        $resend = (bool) $this->option('resend');

        if ($email === '' || $changeReference === '' || (! $resend && $name === '')) {
            $this->error('--email and --change-ref are required; --name is required for initial provisioning.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)
            || mb_strlen($name) > 120
            || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,99}$/', $changeReference)) {
            $this->error('Administrator details or change reference are invalid.');

            return self::FAILURE;
        }

        if (! OneTimeInvitationMailGuard::canDeliver()) {
            $this->error('The configured mail transport cannot deliver setup links without writing their secret URL to logs. Configure a delivery mailer before bootstrapping.');

            return self::FAILURE;
        }

        $result = SystemContext::run(
            SystemOperation::OpsConsole,
            'Platform Super Admin bootstrap; operator reference '.$changeReference,
            function () use ($name, $email, $changeReference, $resend, $audit): array {
                DB::statement('LOCK TABLE platform_super_admins IN EXCLUSIVE MODE');
                $admins = DB::table('platform_super_admins as platform_admin')
                    ->join('users', 'users.id', '=', 'platform_admin.user_id')
                    ->lockForUpdate()
                    ->get([
                        'platform_admin.id',
                        'platform_admin.user_id',
                        'platform_admin.status',
                        'users.email',
                        'users.email_verified_at',
                        'users.status as user_status',
                    ]);

                if ($admins->count() > 1) {
                    return ['error' => 'Multiple platform administrator records require operator review.'];
                }

                $admin = $admins->first();
                if ($admin !== null) {
                    if ($admin->status !== 'PENDING'
                        || $admin->email_verified_at !== null
                        || $admin->user_status !== 'PENDING') {
                        return ['error' => 'A platform administrator already exists; additional or replacement administrators require a separate governed workflow.'];
                    }
                    if ($admin->email !== $email) {
                        return ['error' => 'A pending platform administrator invitation already exists for another email.'];
                    }
                    if (! $resend) {
                        return ['error' => 'A pending invitation exists; use --resend for the same email to rotate its link.'];
                    }
                } elseif ($resend) {
                    return ['error' => 'No pending platform administrator invitation exists for that email.'];
                } elseif (DB::table('users')->where('email', $email)->exists()) {
                    return ['error' => 'That email already belongs to an account.'];
                }

                $userId = $admin->user_id ?? (string) Str::uuid7();
                $platformAdminId = $admin->id ?? (string) Str::uuid7();
                $token = Str::random(64);
                $tokenHash = hash('sha256', $token);
                $expiresAt = now()->addMinutes(60);

                if ($admin === null) {
                    DB::table('users')->insert([
                        'id' => $userId,
                        'tenant_id' => null,
                        'name' => $name,
                        'email' => $email,
                        'password' => Hash::make(Str::random(80)),
                        'status' => 'PENDING',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    DB::table('platform_super_admins')->insert([
                        'id' => $platformAdminId,
                        'user_id' => $userId,
                        'status' => 'PENDING',
                        'created_by_reference' => $changeReference,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                if ($admin === null) {
                    DB::table('platform_super_admin_bootstrap_tokens')->insert([
                        'id' => (string) Str::uuid7(),
                        'user_id' => $userId,
                        'token_hash' => $tokenHash,
                        'created_by_reference' => $changeReference,
                        'expires_at' => $expiresAt,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $pendingToken = DB::table('platform_super_admin_bootstrap_tokens')
                        ->where('user_id', $userId)
                        ->whereNull('consumed_at')
                        ->lockForUpdate()
                        ->first();
                    if ($pendingToken === null) {
                        return ['error' => 'The pending platform administrator has no unconsumed setup token.'];
                    }
                    DB::table('platform_super_admin_bootstrap_tokens')
                        ->where('id', $pendingToken->id)
                        ->update([
                            'token_hash' => $tokenHash,
                            'created_by_reference' => $changeReference,
                            'expires_at' => $expiresAt,
                            'updated_at' => now(),
                        ]);
                }

                $audit->system(
                    $admin === null
                        ? 'platform.super_admin.bootstrap_invited'
                        : 'platform.super_admin.bootstrap_invitation_resent',
                    'Operator provisioning reference: '.$changeReference,
                    ['target_user_id' => $userId, 'provisioning_reference' => $changeReference],
                );

                return ['email' => $email, 'token' => $token];
            },
        );

        if (isset($result['error'])) {
            $this->error($result['error']);

            return self::FAILURE;
        }

        Mail::to($result['email'])->send(new PlatformSuperAdminInvitation($result['token']));
        $this->info('The initial platform Super Admin setup invitation was sent. The setup link expires in 60 minutes.');

        return self::SUCCESS;
    }
}
