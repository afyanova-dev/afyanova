<?php

declare(strict_types=1);

namespace App\Domains\Authentication\Application;

use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class PlatformSuperAdminAccessService
{
    /**
     * @return array{
     *   administrators: list<array{id: string, name: string, email: string, status: string, twoFactorEnabled: bool}>,
     *   requests: list<array{id: string, targetId: string, targetName: string, requesterId: string, requesterName: string, reviewerName: string|null, action: string, status: string, reason: string, reviewNotes: string|null, createdAt: string}>
     * }
     */
    public function overview(): array
    {
        $administrators = DB::table('platform_super_admins as platform_admin')
            ->join('users', 'users.id', '=', 'platform_admin.user_id')
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'platform_admin.status',
                'users.two_factor_confirmed_at',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'name' => (string) $row->name,
                'email' => (string) $row->email,
                'status' => (string) $row->status,
                'twoFactorEnabled' => $row->two_factor_confirmed_at !== null,
            ])
            ->all();
        $administrators = array_values($administrators);

        $requests = DB::table('platform_super_admin_access_requests as request')
            ->join('users as target', 'target.id', '=', 'request.target_user_id')
            ->join('users as requester', 'requester.id', '=', 'request.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'request.reviewed_by')
            ->orderByRaw("CASE WHEN request.status = 'PENDING' THEN 0 ELSE 1 END")
            ->orderByDesc('request.created_at')
            ->limit(100)
            ->get([
                'request.id',
                'request.target_user_id',
                'target.name as target_name',
                'request.requested_by',
                'requester.name as requester_name',
                'reviewer.name as reviewer_name',
                'request.action',
                'request.status',
                'request.reason',
                'request.review_notes',
                'request.created_at',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'targetId' => (string) $row->target_user_id,
                'targetName' => (string) $row->target_name,
                'requesterId' => (string) $row->requested_by,
                'requesterName' => (string) $row->requester_name,
                'reviewerName' => $row->reviewer_name === null ? null : (string) $row->reviewer_name,
                'action' => (string) $row->action,
                'status' => (string) $row->status,
                'reason' => (string) $row->reason,
                'reviewNotes' => $row->review_notes === null ? null : (string) $row->review_notes,
                'createdAt' => (string) $row->created_at,
            ])
            ->all();
        $requests = array_values($requests);

        return ['administrators' => $administrators, 'requests' => $requests];
    }

    public function requestChange(string $targetId, string $action, string $reason, string $requesterId): string
    {
        if (! in_array($action, ['SUSPEND', 'REACTIVATE', 'RESET_MFA'], true)) {
            throw new RuntimeException('Unsupported Platform Super Admin access action.');
        }

        return DB::transaction(function () use ($targetId, $action, $reason, $requesterId): string {
            $this->activeAdministrator($requesterId);
            $target = DB::table('platform_super_admins as platform_admin')
                ->join('users', 'users.id', '=', 'platform_admin.user_id')
                ->where('platform_admin.user_id', $targetId)
                ->lockForUpdate()
                ->first([
                    'platform_admin.status',
                    'users.name',
                    'users.tenant_id',
                    'users.status as user_status',
                    'users.two_factor_confirmed_at',
                ]);

            if ($target === null || $target->tenant_id !== null || $target->user_status !== 'ACTIVE') {
                throw new RuntimeException('The target is not an eligible Platform Super Admin account.');
            }
            if ($targetId === $requesterId) {
                throw new RuntimeException('A Platform Super Admin cannot request an access change for their own account.');
            }
            if (($action === 'SUSPEND' && $target->status !== 'ACTIVE')
                || ($action === 'REACTIVATE' && $target->status !== 'SUSPENDED')
                || ($action === 'RESET_MFA'
                    && ($target->status !== 'ACTIVE' || $target->two_factor_confirmed_at === null))) {
                throw new RuntimeException('The requested action is not valid for the target account state.');
            }
            if (DB::table('platform_super_admin_access_requests')
                ->where('target_user_id', $targetId)
                ->where('action', $action)
                ->where('status', 'PENDING')
                ->exists()) {
                throw new RuntimeException('A matching access request is already awaiting review.');
            }

            $requestId = (string) Str::uuid7();
            DB::table('platform_super_admin_access_requests')->insert([
                'id' => $requestId,
                'target_user_id' => $targetId,
                'requested_by' => $requesterId,
                'action' => $action,
                'status' => 'PENDING',
                'reason' => trim($reason),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(AuditLogger::class)->recordPlatform(
                action: 'platform.super_admin.access_requested',
                actorId: $requesterId,
                targetType: 'user',
                targetId: $targetId,
                reason: trim($reason),
                metadata: ['state' => $action, 'status' => 'PENDING'],
            );

            return $requestId;
        });
    }

    public function reviewRequest(
        string $requestId,
        string $reviewerId,
        bool $approve,
        string $reviewNotes,
    ): void {
        DB::transaction(function () use ($requestId, $reviewerId, $approve, $reviewNotes): void {
            $accessRequest = DB::table('platform_super_admin_access_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();
            if ($accessRequest === null || $accessRequest->status !== 'PENDING') {
                throw new RuntimeException('The Platform Super Admin access request is no longer pending.');
            }
            if ($accessRequest->requested_by === $reviewerId || $accessRequest->target_user_id === $reviewerId) {
                throw new RuntimeException('The requester and target cannot review this access request.');
            }

            $administrators = DB::table('platform_super_admins as platform_admin')
                ->join('users', 'users.id', '=', 'platform_admin.user_id')
                ->orderBy('platform_admin.user_id')
                ->lockForUpdate()
                ->get([
                    'platform_admin.user_id',
                    'platform_admin.status',
                    'platform_admin.access_version',
                    'users.tenant_id',
                    'users.status as user_status',
                ]);
            $reviewerIsActive = $administrators->contains(
                static fn (object $administrator): bool => $administrator->user_id === $reviewerId
                    && $administrator->status === 'ACTIVE'
                    && $administrator->tenant_id === null
                    && $administrator->user_status === 'ACTIVE',
            );
            if (! $reviewerIsActive) {
                throw new RuntimeException('Active Platform Super Admin access is required.');
            }
            $target = $administrators->first(
                static fn (object $administrator): bool => $administrator->user_id === $accessRequest->target_user_id,
            );
            if ($target === null) {
                throw new RuntimeException('The target Platform Super Admin account no longer exists.');
            }
            if ($approve && ($target->tenant_id !== null || $target->user_status !== 'ACTIVE')) {
                throw new RuntimeException('The target account is no longer eligible for a platform access change.');
            }

            if ($approve) {
                if ($accessRequest->action === 'SUSPEND') {
                    if ($target->status !== 'ACTIVE'
                        || $administrators->filter(static fn (object $administrator): bool => $administrator->status === 'ACTIVE'
                            && $administrator->tenant_id === null
                            && $administrator->user_status === 'ACTIVE')->count() <= 1) {
                        throw new RuntimeException('The request cannot suspend the last active Platform Super Admin.');
                    }
                    DB::table('platform_super_admins')
                        ->where('user_id', $accessRequest->target_user_id)
                        ->update([
                            'status' => 'SUSPENDED',
                            'access_version' => $target->access_version + 1,
                            'updated_at' => now(),
                        ]);
                } elseif ($accessRequest->action === 'REACTIVATE') {
                    if ($target->status !== 'SUSPENDED') {
                        throw new RuntimeException('Only a suspended Platform Super Admin can be reactivated.');
                    }
                    DB::table('platform_super_admins')
                        ->where('user_id', $accessRequest->target_user_id)
                        ->update([
                            'status' => 'ACTIVE',
                            'access_version' => $target->access_version + 1,
                            'updated_at' => now(),
                        ]);
                } elseif ($accessRequest->action === 'RESET_MFA') {
                    $mfaIsEnabled = DB::table('users')
                        ->where('id', $accessRequest->target_user_id)
                        ->whereNotNull('two_factor_confirmed_at')
                        ->exists();
                    if ($target->status !== 'ACTIVE' || ! $mfaIsEnabled) {
                        throw new RuntimeException('MFA recovery requires an active Platform Super Admin account.');
                    }
                    DB::table('users')->where('id', $accessRequest->target_user_id)->update([
                        'two_factor_secret' => null,
                        'two_factor_recovery_codes' => null,
                        'two_factor_confirmed_at' => null,
                        'updated_at' => now(),
                    ]);
                    DB::table('platform_super_admins')
                        ->where('user_id', $accessRequest->target_user_id)
                        ->update([
                            'access_version' => $target->access_version + 1,
                            'updated_at' => now(),
                        ]);
                }
            }

            DB::table('platform_super_admin_access_requests')
                ->where('id', $requestId)
                ->update([
                    'status' => $approve ? 'APPROVED' : 'REJECTED',
                    'reviewed_by' => $reviewerId,
                    'review_notes' => trim($reviewNotes),
                    'reviewed_at' => now(),
                    'updated_at' => now(),
                ]);
            app(AuditLogger::class)->recordPlatform(
                action: $approve ? 'platform.super_admin.access_approved' : 'platform.super_admin.access_rejected',
                actorId: $reviewerId,
                targetType: 'user',
                targetId: (string) $accessRequest->target_user_id,
                reason: trim($reviewNotes),
                metadata: [
                    'state' => (string) $accessRequest->action,
                    'status' => $approve ? 'APPROVED' : 'REJECTED',
                ],
            );
        });
    }

    private function activeAdministrator(string $userId): void
    {
        $administrator = DB::table('platform_super_admins as platform_admin')
            ->join('users', 'users.id', '=', 'platform_admin.user_id')
            ->where('platform_admin.user_id', $userId)
            ->where('platform_admin.status', 'ACTIVE')
            ->whereNull('users.tenant_id')
            ->where('users.status', 'ACTIVE')
            ->first(['platform_admin.status']);

        if ($administrator === null) {
            throw new RuntimeException('Active Platform Super Admin access is required.');
        }
    }
}
