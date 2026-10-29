<?php

declare(strict_types=1);

namespace App\Domains\Identity\Application;

use App\Domains\Authentication\Contracts\FacilityUserRoleCatalog;
use App\Domains\Identity\Contracts\LinkedStaffLifecycleApi;
use App\Models\User;
use App\Shared\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tenant-wide identity effects for linked HR staff lifecycle events.
 *
 * Policy (explicit, review before changing):
 * - HR suspension is tenant-wide (Staff.status is tenant-wide): ordinary
 *   assignments are suspended immediately and account access is blocked.
 *   Privileged assignments remain unchanged pending independent approval.
 * - HR termination is tenant-wide and terminal: revokeAllAccessForStaff
 *   REVOKES ordinary ACTIVE/SUSPENDED assignments immediately. Privileged
 *   assignments remain unchanged pending independent approval; account access
 *   is blocked immediately by the HR status.
 * - HR reinstatement restores only assignments recorded by that specific
 *   suspension operation and unchanged since that operation.
 * - HR employment history remains separate from identity assignments.
 * - Staff with no linked user: no identity effect (HR-only).
 *
 * All methods assume the caller already holds a tenant context (request or
 * `TenantContextScope`) and wraps HR + identity + audit in one DB transaction.
 * They revoke `aut_sessions` device-tracking rows. HR status and session
 * revocation are enforced against Laravel sessions on each web request.
 */
final class LinkedStaffAccessService implements LinkedStaffLifecycleApi
{
    /**
     * Serialize HR lifecycle changes against all active role-grant paths.
     */
    public function lockLinkedUserForLifecycle(string $tenantId, string $staffId): ?string
    {
        $userId = $this->linkedUserId($tenantId, $staffId);
        if ($userId === null) {
            return null;
        }

        $user = User::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();
        abort_unless($user instanceof User, 404, 'The linked user is unavailable in this tenant.');

        return $userId;
    }

    /**
     * Tenant-wide suspension: suspend ordinary assignments immediately,
     * block login/sessions, and require independent approval before changing
     * administrator assignments.
     *
     * @return array{userId: string|null, suspensionId: string|null, assignments: list<string>, privilegedRequests: list<string>, sessionsRevoked: int}
     */
    public function suspendForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
    ): array {
        $userId = $this->linkedUserId($tenantId, $staffId);
        if ($userId === null) {
            return ['userId' => null, 'suspensionId' => null, 'assignments' => [], 'privilegedRequests' => [], 'sessionsRevoked' => 0];
        }

        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->first();
        abort_unless($user instanceof User, 404, 'The linked user is unavailable in this tenant.');

        $rows = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.user_id', $userId)
            ->where('assignment.status', 'ACTIVE')
            ->lockForUpdate()
            ->get([
                'assignment.id',
                'assignment.facility_id',
                'assignment.organization_id',
                'assignment.version',
                'assignment.status',
                'role.key as role_key',
                'role.id as role_id',
            ]);

        $assignmentIds = [];
        $ordinaryRows = $rows->reject(fn (object $row): bool => $this->isPrivilegedRole((string) $row->role_key));
        $privilegedRows = $rows->filter(fn (object $row): bool => $this->isPrivilegedRole((string) $row->role_key));
        $byScope = $this->groupByScope($ordinaryRows, $assignmentIds);

        $suspensionId = (string) Str::uuid7();
        DB::table('idn_staff_access_suspensions')->insert([
            'id' => $suspensionId,
            'tenant_id' => $tenantId,
            'staff_id' => $staffId,
            'user_id' => $userId,
            'actor_id' => $actorId,
            'status' => 'ACTIVE',
            'reason' => $reason,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($assignmentIds !== []) {
            DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $assignmentIds)
                ->update(['status' => 'SUSPENDED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

            foreach ($ordinaryRows as $row) {
                DB::table('idn_staff_access_suspension_assignments')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $tenantId,
                    'suspension_id' => $suspensionId,
                    'assignment_id' => (string) $row->id,
                    'assignment_version' => (int) $row->version + 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $privilegedRequests = $this->requestPrivilegedRevocations(
            tenantId: $tenantId,
            staffId: $staffId,
            userId: $userId,
            actorId: $actorId,
            reason: $reason,
            rows: $privilegedRows,
            audit: $audit,
        );
        $sessionsRevoked = $this->revokeSessions($tenantId, $userId);

        $this->auditScopeChanges(
            tenantId: $tenantId,
            action: 'identity.access.suspended',
            actorId: $actorId,
            staffId: $staffId,
            reason: $reason,
            status: 'SUSPENDED',
            byScope: $byScope,
            sessionsRevoked: $sessionsRevoked,
            audit: $audit,
            targetType: 'user',
            targetId: $userId,
        );

        return [
            'userId' => $userId,
            'suspensionId' => $suspensionId,
            'assignments' => $assignmentIds,
            'privilegedRequests' => $privilegedRequests,
            'sessionsRevoked' => $sessionsRevoked,
        ];
    }

    /**
     * @return array{userId: string|null, assignments: list<string>, sessionsRevoked: int}
     */
    public function revokeForStaff(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
    ): array {
        $userId = $this->linkedUserId($tenantId, $staffId);
        if ($userId === null) {
            return ['userId' => null, 'assignments' => [], 'sessionsRevoked' => 0];
        }

        $assignmentIds = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('facility_id', $facilityId)
            ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->values()
            ->all();

        if ($assignmentIds !== []) {
            DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $assignmentIds)
                ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }

        $sessionsRevoked = $this->revokeSessions($tenantId, $userId);

        $audit->recordFor(
            tenantId: $tenantId,
            action: 'identity.access.revoked',
            actorId: $actorId,
            facilityId: $facilityId,
            reason: $reason,
            metadata: [
                'staff' => $staffId,
                'status' => 'REVOKED',
                'assignments' => $assignmentIds,
                'sessions_revoked' => $sessionsRevoked,
            ],
            targetType: 'user',
            targetId: $userId,
        );

        return ['userId' => $userId, 'assignments' => array_values($assignmentIds), 'sessionsRevoked' => $sessionsRevoked];
    }

    /**
     * Restore only assignments changed by the specific open HR suspension.
     *
     * @return array{userId: string|null, assignments: list<string>}
     */
    public function restoreForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        AuditLogger $audit,
    ): array {
        $userId = $this->linkedUserId($tenantId, $staffId);
        if ($userId === null) {
            return ['userId' => null, 'assignments' => []];
        }

        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->first();
        abort_unless($user instanceof User, 404, 'The linked user is unavailable in this tenant.');

        $suspension = DB::table('idn_staff_access_suspensions')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->lockForUpdate()
            ->orderByDesc('created_at')
            ->first();
        if ($suspension === null) {
            return ['userId' => $userId, 'assignments' => []];
        }

        $rows = DB::table('idn_staff_access_suspension_assignments as suspension_assignment')
            ->join('idn_access_assignments as assignment', function ($join) use ($tenantId): void {
                $join->on('assignment.id', '=', 'suspension_assignment.assignment_id')
                    ->where('assignment.tenant_id', '=', $tenantId);
            })
            ->where('suspension_assignment.tenant_id', $tenantId)
            ->where('suspension_assignment.suspension_id', $suspension->id)
            ->where('assignment.user_id', $userId)
            ->where('assignment.status', 'SUSPENDED')
            ->whereColumn('assignment.version', 'suspension_assignment.assignment_version')
            ->lockForUpdate()
            ->get(['assignment.id', 'assignment.facility_id']);

        $assignmentIds = [];
        $byScope = $this->groupByScope($rows, $assignmentIds);

        if ($assignmentIds !== []) {
            DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $assignmentIds)
                ->where('status', 'SUSPENDED')
                ->update(['status' => 'ACTIVE', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }

        $this->cancelPendingLifecycleRevocations($tenantId, $staffId, $actorId, $audit);

        DB::table('idn_staff_access_suspensions')
            ->where('tenant_id', $tenantId)
            ->where('id', $suspension->id)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'RESTORED', 'updated_at' => now()]);

        $this->auditScopeChanges(
            tenantId: $tenantId,
            action: 'identity.access.restored',
            actorId: $actorId,
            staffId: $staffId,
            reason: null,
            status: 'ACTIVE',
            byScope: $byScope,
            sessionsRevoked: null,
            audit: $audit,
            targetType: 'user',
            targetId: $userId,
        );

        return ['userId' => $userId, 'assignments' => $assignmentIds];
    }

    private function cancelPendingLifecycleRevocations(
        string $tenantId,
        string $staffId,
        string $actorId,
        AuditLogger $audit,
    ): void {
        $requests = DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('hr_staff_id', $staffId)
            ->where('request_origin', 'HR_LIFECYCLE')
            ->where('action', 'REVOKE')
            ->where('status', 'PENDING')
            ->lockForUpdate()
            ->get(['id', 'assignment_id', 'facility_id', 'role_key', 'scope_type']);

        foreach ($requests as $request) {
            DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $tenantId)
                ->where('id', $request->id)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'CANCELLED',
                    'decision_reason' => 'Automatically cancelled because the linked staff member was reinstated.',
                    'decided_by' => $actorId,
                    'decided_at' => now(),
                    'updated_at' => now(),
                ]);

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'identity.admin_role_promotion.revocation_cancelled',
                actorId: $actorId,
                facilityId: $request->facility_id === null ? null : (string) $request->facility_id,
                reason: 'Linked staff member was reinstated before the access revocation was approved.',
                metadata: [
                    'promotion_request' => (string) $request->id,
                    'assignment' => $request->assignment_id === null ? null : (string) $request->assignment_id,
                    'role' => (string) $request->role_key,
                    'scope' => (string) $request->scope_type,
                    'status' => 'CANCELLED',
                ],
                targetType: 'assignment',
                targetId: (string) $request->assignment_id,
            );
        }
    }

    /**
     * Tenant-wide termination effect: revoke ordinary assignments immediately.
     * Privileged assignments remain unchanged until independent approval.
     *
     * @return array{userId: string|null, facilities: list<string>, assignments: list<string>, privilegedRequests: list<string>, sessionsRevoked: int}
     */
    public function revokeAllAccessForStaff(
        string $tenantId,
        string $staffId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
    ): array {
        $userId = $this->linkedUserId($tenantId, $staffId);
        if ($userId === null) {
            return ['userId' => null, 'facilities' => [], 'assignments' => [], 'privilegedRequests' => [], 'sessionsRevoked' => 0];
        }

        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->first();
        abort_unless($user instanceof User, 404, 'The linked user is unavailable in this tenant.');

        DB::table('idn_staff_access_suspensions')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->where('user_id', $userId)
            ->where('status', 'ACTIVE')
            ->update(['status' => 'TERMINATED', 'updated_at' => now()]);

        $rows = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.user_id', $userId)
            ->whereIn('assignment.status', ['ACTIVE', 'SUSPENDED'])
            ->lockForUpdate()
            ->get([
                'assignment.id',
                'assignment.facility_id',
                'assignment.organization_id',
                'assignment.version',
                'assignment.status',
                'role.key as role_key',
                'role.id as role_id',
            ]);

        $assignmentIds = [];
        $ordinaryRows = $rows->reject(fn (object $row): bool => $this->isPrivilegedRole((string) $row->role_key));
        $privilegedRows = $rows->filter(fn (object $row): bool => $this->isPrivilegedRole((string) $row->role_key));
        $byScope = $this->groupByScope($ordinaryRows, $assignmentIds);

        if ($assignmentIds !== []) {
            DB::table('idn_access_assignments')
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $assignmentIds)
                ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
        }

        $privilegedRequests = $this->requestPrivilegedRevocations(
            tenantId: $tenantId,
            staffId: $staffId,
            userId: $userId,
            actorId: $actorId,
            reason: $reason,
            rows: $privilegedRows,
            audit: $audit,
        );
        $sessionsRevoked = $this->revokeSessions($tenantId, $userId);

        $this->auditScopeChanges(
            tenantId: $tenantId,
            action: 'identity.access.revoked',
            actorId: $actorId,
            staffId: $staffId,
            reason: $reason,
            status: 'REVOKED',
            byScope: $byScope,
            sessionsRevoked: $sessionsRevoked,
            audit: $audit,
            targetType: 'user',
            targetId: $userId,
        );

        return [
            'userId' => $userId,
            'facilities' => array_keys($byScope['facilities']),
            'assignments' => $assignmentIds,
            'privilegedRequests' => $privilegedRequests,
            'sessionsRevoked' => $sessionsRevoked,
        ];
    }

    /**
     * @param  iterable<\stdClass>  $rows
     * @return list<string>
     */
    private function requestPrivilegedRevocations(
        string $tenantId,
        string $staffId,
        string $userId,
        string $actorId,
        ?string $reason,
        iterable $rows,
        AuditLogger $audit,
    ): array {
        $requestIds = [];
        foreach ($rows as $row) {
            $assignmentId = (string) $row->id;
            $existingRequest = DB::table('idn_admin_role_promotion_requests')
                ->where('tenant_id', $tenantId)
                ->where('assignment_id', $assignmentId)
                ->where('action', 'REVOKE')
                ->where('status', 'PENDING')
                ->exists();
            if ($existingRequest) {
                continue;
            }

            $requestId = (string) Str::uuid7();
            $facilityId = $row->facility_id === null ? null : (string) $row->facility_id;
            DB::table('idn_admin_role_promotion_requests')->insert([
                'id' => $requestId,
                'tenant_id' => $tenantId,
                'organization_id' => (string) $row->organization_id,
                'facility_id' => $facilityId,
                'target_user_id' => $userId,
                'role_id' => (string) $row->role_id,
                'role_key' => (string) $row->role_key,
                'scope_type' => $facilityId === null ? 'ORG' : 'FACILITY',
                'action' => 'REVOKE',
                'request_origin' => 'HR_LIFECYCLE',
                'hr_staff_id' => $staffId,
                'status' => 'PENDING',
                'reason' => $reason ?? 'HR lifecycle status requires independent access review.',
                'requested_by' => $actorId,
                'assignment_id' => $assignmentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $audit->recordFor(
                tenantId: $tenantId,
                action: 'identity.admin_role_promotion.revocation_requested',
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: [
                    'promotion_request' => $requestId,
                    'assignment' => $assignmentId,
                    'role' => (string) $row->role_key,
                    'scope' => $facilityId === null ? 'ORG' : 'FACILITY',
                    'staff' => $staffId,
                    'status' => 'PENDING',
                ],
                targetType: 'assignment',
                targetId: $assignmentId,
            );
            $requestIds[] = $requestId;
        }

        return $requestIds;
    }

    private function isPrivilegedRole(string $roleKey): bool
    {
        return in_array($roleKey, ['tenant_admin', 'facility_admin'], true);
    }

    public function manageFacilityStaffAssignment(
        string $tenantId,
        string $staffId,
        string $assignmentId,
        string $facilityId,
        string $action,
        string $actorId,
        string $reason,
        AuditLogger $audit,
    ): void {
        abort_unless(in_array($action, ['suspend', 'restore', 'revoke'], true), 404);

        DB::transaction(function () use (
            $tenantId,
            $staffId,
            $assignmentId,
            $facilityId,
            $action,
            $actorId,
            $reason,
            $audit,
        ): void {
            $target = DB::table('hr_staff_user_links as link')
                ->join('idn_access_assignments as assignment', function ($join): void {
                    $join->on('assignment.user_id', '=', 'link.user_id')
                        ->on('assignment.tenant_id', '=', 'link.tenant_id');
                })
                ->join('idn_roles as role', function ($join): void {
                    $join->on('role.id', '=', 'assignment.role_id')
                        ->on('role.tenant_id', '=', 'assignment.tenant_id');
                })
                ->where('link.tenant_id', $tenantId)
                ->where('link.staff_id', $staffId)
                ->where('assignment.id', $assignmentId)
                ->where('assignment.facility_id', $facilityId)
                ->lockForUpdate()
                ->first(['role.key as role_key']);
            abort_unless($target !== null, 404);
            abort_unless(array_key_exists((string) $target->role_key, FacilityUserRoleCatalog::STAFF_ROLES), 422);

            if ($action === 'suspend') {
                $this->suspendAssignment($tenantId, $assignmentId, $actorId, $reason, $audit, $facilityId);
            } elseif ($action === 'restore') {
                $this->restoreAssignment($tenantId, $assignmentId, $actorId, $reason, $audit, $facilityId);
            } else {
                $this->revokeAssignment($tenantId, $assignmentId, $actorId, $reason, $audit, $facilityId);
            }
        });
    }

    /**
     * Revoke a single facility assignment (ordinary access management).
     *
     * @return array{userId: string, facilityId: string, sessionsRevoked: int}
     */
    public function revokeAssignment(
        string $tenantId,
        string $assignmentId,
        string $actorId,
        ?string $reason,
        AuditLogger $audit,
        ?string $facilityId = null,
    ): array {
        $query = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $assignmentId);
        if ($facilityId !== null) {
            $query->where('facility_id', $facilityId);
        }
        $row = $query->lockForUpdate()
            ->first(['id', 'user_id', 'facility_id', 'status']);

        abort_unless($row !== null && $row->facility_id !== null, 404);
        if ($facilityId !== null) {
            $roleKey = DB::table('idn_access_assignments as assignment')
                ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
                ->where('assignment.tenant_id', $tenantId)
                ->where('assignment.id', $assignmentId)
                ->value('role.key');
            abort_unless(is_string($roleKey)
                && array_key_exists($roleKey, FacilityUserRoleCatalog::STAFF_ROLES), 422);
        }
        abort_if($row->status !== 'ACTIVE' && $row->status !== 'SUSPENDED', 409, 'This access assignment is no longer active.');

        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $row->id)
            ->update(['status' => 'REVOKED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        $sessionsRevoked = $this->revokeSessions($tenantId, (string) $row->user_id);

        $audit->recordFor(
            tenantId: $tenantId,
            action: 'identity.access.revoked',
            actorId: $actorId,
            facilityId: (string) $row->facility_id,
            reason: $reason,
            metadata: [
                'assignment' => (string) $row->id,
                'status' => 'REVOKED',
                'sessions_revoked' => $sessionsRevoked,
            ],
            targetType: 'assignment',
            targetId: (string) $row->id,
        );

        return ['userId' => (string) $row->user_id, 'facilityId' => (string) $row->facility_id, 'sessionsRevoked' => $sessionsRevoked];
    }

    /**
     * Temporarily suspend one ordinary facility assignment without changing
     * the linked staff record or any of the user's other assignments.
     *
     * @return array{userId: string, facilityId: string, sessionsRevoked: int}
     */
    public function suspendAssignment(
        string $tenantId,
        string $assignmentId,
        string $actorId,
        string $reason,
        AuditLogger $audit,
        string $facilityId,
    ): array {
        $row = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.id', $assignmentId)
            ->where('assignment.facility_id', $facilityId)
            ->lockForUpdate()
            ->first([
                'assignment.id',
                'assignment.user_id',
                'assignment.facility_id',
                'assignment.status',
                'role.key as role_key',
            ]);
        abort_unless($row !== null && $row->facility_id !== null, 404);
        abort_unless(array_key_exists((string) $row->role_key, FacilityUserRoleCatalog::STAFF_ROLES), 422);
        abort_unless($row->status === 'ACTIVE', 409, 'Only active access assignments can be suspended.');
        $this->assertStaffRoleCanBeManaged($tenantId, (string) $row->user_id);

        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $row->id)
            ->update(['status' => 'SUSPENDED', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        $sessionsRevoked = $this->revokeSessions($tenantId, (string) $row->user_id);
        $audit->recordFor(
            tenantId: $tenantId,
            action: 'identity.access.suspended',
            actorId: $actorId,
            facilityId: (string) $row->facility_id,
            reason: $reason,
            metadata: [
                'assignment' => (string) $row->id,
                'role' => (string) $row->role_key,
                'status' => 'SUSPENDED',
                'sessions_revoked' => $sessionsRevoked,
            ],
            targetType: 'assignment',
            targetId: (string) $row->id,
        );

        return [
            'userId' => (string) $row->user_id,
            'facilityId' => (string) $row->facility_id,
            'sessionsRevoked' => $sessionsRevoked,
        ];
    }

    /**
     * Restore a role-level suspension only. Staff lifecycle suspensions are
     * owned and restored by the HR lifecycle workflow.
     *
     * @return array{userId: string, facilityId: string}
     */
    public function restoreAssignment(
        string $tenantId,
        string $assignmentId,
        string $actorId,
        string $reason,
        AuditLogger $audit,
        string $facilityId,
    ): array {
        $row = DB::table('idn_access_assignments as assignment')
            ->join('idn_roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.id', $assignmentId)
            ->where('assignment.facility_id', $facilityId)
            ->lockForUpdate()
            ->first([
                'assignment.id',
                'assignment.user_id',
                'assignment.facility_id',
                'assignment.status',
                'role.key as role_key',
            ]);
        abort_unless($row !== null && $row->facility_id !== null, 404);
        abort_unless(array_key_exists((string) $row->role_key, FacilityUserRoleCatalog::STAFF_ROLES), 422);
        abort_unless($row->status === 'SUSPENDED', 409, 'Only suspended access assignments can be restored.');
        $staffId = $this->assertStaffRoleCanBeManaged($tenantId, (string) $row->user_id);
        $lifecycleSuspension = DB::table('idn_staff_access_suspension_assignments as suspension_assignment')
            ->join('idn_staff_access_suspensions as suspension', function ($join) use ($tenantId): void {
                $join->on('suspension.id', '=', 'suspension_assignment.suspension_id')
                    ->where('suspension.tenant_id', '=', $tenantId);
            })
            ->where('suspension_assignment.tenant_id', $tenantId)
            ->where('suspension_assignment.assignment_id', $assignmentId)
            ->where('suspension.staff_id', $staffId)
            ->where('suspension.status', 'ACTIVE')
            ->exists();
        abort_if($lifecycleSuspension, 409, 'Restore this role through the staff reinstatement workflow.');

        DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('id', $row->id)
            ->update(['status' => 'ACTIVE', 'version' => DB::raw('version + 1'), 'updated_at' => now()]);

        $audit->recordFor(
            tenantId: $tenantId,
            action: 'identity.access.restored',
            actorId: $actorId,
            facilityId: (string) $row->facility_id,
            reason: $reason,
            metadata: [
                'assignment' => (string) $row->id,
                'role' => (string) $row->role_key,
                'status' => 'ACTIVE',
            ],
            targetType: 'assignment',
            targetId: (string) $row->id,
        );

        return ['userId' => (string) $row->user_id, 'facilityId' => (string) $row->facility_id];
    }

    private function assertStaffRoleCanBeManaged(string $tenantId, string $userId): string
    {
        $staff = DB::table('hr_staff_user_links as link')
            ->join('hr_staff as staff', function ($join): void {
                $join->on('staff.id', '=', 'link.staff_id')
                    ->on('staff.tenant_id', '=', 'link.tenant_id');
            })
            ->where('link.tenant_id', $tenantId)
            ->where('link.user_id', $userId)
            ->first(['staff.id', 'staff.status']);
        abort_unless($staff !== null, 404, 'The account is not linked to staff.');
        abort_if(
            in_array((string) $staff->status, ['SUSPENDED', 'TERMINATED'], true),
            409,
            'Manage this access through the staff suspension or termination workflow.',
        );

        return (string) $staff->id;
    }

    /**
     * Group assignment rows by scope for per-scope audit attribution.
     *
     * @param  iterable<\stdClass>  $rows
     * @param  list<string>  $assignmentIds  (filled in traversal order)
     * @return array{facilities: array<string, list<string>>, organization: list<string>}
     */
    private function groupByScope(iterable $rows, array &$assignmentIds): array
    {
        $byScope = ['facilities' => [], 'organization' => []];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $assignmentIds[] = $id;
            if ($row->facility_id === null) {
                $byScope['organization'][] = $id;
            } else {
                $facility = (string) $row->facility_id;
                $byScope['facilities'][$facility][] = $id;
            }
        }

        return $byScope;
    }

    /**
     * @param  array{facilities: array<string, list<string>>, organization: list<string>}  $byScope
     */
    private function auditScopeChanges(
        string $tenantId,
        string $action,
        string $actorId,
        string $staffId,
        ?string $reason,
        string $status,
        array $byScope,
        ?int $sessionsRevoked,
        AuditLogger $audit,
        string $targetType,
        string $targetId,
    ): void {
        foreach ($byScope['facilities'] as $facilityId => $ids) {
            $metadata = [
                'staff' => $staffId,
                'status' => $status,
                'assignments' => $ids,
            ];
            if ($sessionsRevoked !== null) {
                $metadata['sessions_revoked'] = $sessionsRevoked;
            }
            $audit->recordFor(
                tenantId: $tenantId,
                action: $action,
                actorId: $actorId,
                facilityId: $facilityId,
                reason: $reason,
                metadata: $metadata,
                targetType: $targetType,
                targetId: $targetId,
            );
        }
        if ($byScope['organization'] !== []) {
            $metadata = [
                'staff' => $staffId,
                'status' => $status,
                'scope' => 'ORG',
                'assignments' => $byScope['organization'],
            ];
            if ($sessionsRevoked !== null) {
                $metadata['sessions_revoked'] = $sessionsRevoked;
            }
            $audit->recordFor(
                tenantId: $tenantId,
                action: $action,
                actorId: $actorId,
                facilityId: null,
                reason: $reason,
                metadata: $metadata,
                targetType: $targetType,
                targetId: $targetId,
            );
        }
    }

    private function linkedUserId(string $tenantId, string $staffId): ?string
    {
        $userId = DB::table('hr_staff_user_links')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->value('user_id');

        return is_string($userId) ? $userId : null;
    }

    private function revokeSessions(string $tenantId, string $userId): int
    {
        return (int) DB::table('aut_sessions')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);
    }
}
