<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FacilityStaffOnboardingReader
{
    /**
     * One staff row per person who is still somewhere in the onboarding
     * lifecycle, plus active staff who still have an open invitation or a
     * pending role approval. Completed active staff remain in the directory,
     * not in this work queue.
     *
     * @param  list<string>  $roleKeys
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     staffStatus: string,
     *     positionTitle: string|null,
     *     linkedUserId: string|null,
     *     accountEmail: string|null,
     *     accountVerifiedAt: string|null,
     *     placement: array{
     *         state: string,
     *         requestId: string|null,
     *         departmentName: string|null,
     *         unitName: string|null,
     *         effectiveFrom: string|null,
     *         decisionReason: string|null
     *     },
     *     accountState: string,
     *     invitation: array{
     *         id: string|null,
     *         status: string|null,
     *         expiresAt: string|null,
     *         roleKeys: list<string>
     *     },
     *     access: array{
     *         state: string,
     *         pendingCount: int,
     *         roleKeys: list<string>
     *     },
     *     employment: array{
     *         state: string,
     *         status: string|null,
     *         startDate: string|null
     *     }
     * }>
     */
    public function queue(
        string $tenantId,
        string $facilityId,
        string $today,
        string $actorId,
        array $roleKeys,
        bool $canManageAccess,
    ): array {
        $staffRows = DB::table('hr_staff as staff')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'staff_link.user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->where('staff.tenant_id', $tenantId)
            ->whereIn('staff.status', ['PROSPECT', 'ONBOARDING', 'ACTIVE'])
            ->where(function ($query) use (
                $tenantId,
                $facilityId,
                $actorId,
                $roleKeys,
                $canManageAccess,
            ): void {
                $query->whereIn('staff.status', ['PROSPECT', 'ONBOARDING'])
                    ->orWhereExists($this->pendingPlacementExists($tenantId, $facilityId))
                    ->orWhereExists($this->openInvitationExists($tenantId, $facilityId));

                if ($canManageAccess && $roleKeys !== []) {
                    $query->orWhereExists($this->pendingAccessExists(
                        $tenantId,
                        $facilityId,
                        $actorId,
                        $roleKeys,
                    ));
                }
            })
            ->orderBy('person.last_name')
            ->orderBy('person.first_name')
            ->get([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'account.id as linked_user_id',
                'account.email as account_email',
                'account.email_verified_at as account_verified_at',
            ]);

        if ($staffRows->isEmpty()) {
            return [];
        }

        $staffIds = array_values($staffRows
            ->map(static fn (object $row): string => (string) $row->id)
            ->all());
        $userIds = array_values(array_filter($staffRows
            ->map(static fn (object $row): ?string => $row->linked_user_id === null
                ? null
                : (string) $row->linked_user_id)
            ->all()));

        $assignments = $this->currentAssignments($tenantId, $facilityId, $today, $staffIds);
        $pendingPlacements = $this->placements($tenantId, $facilityId, $staffIds, 'PENDING');
        $rejectedPlacements = $this->placements($tenantId, $facilityId, $staffIds, 'REJECTED', $actorId);
        $invitations = $userIds === [] ? [] : $this->latestInvitations($tenantId, $facilityId, $userIds);
        $pendingAccess = $canManageAccess && $userIds !== []
            ? $this->pendingAccessByUser($tenantId, $facilityId, $actorId, $userIds, $roleKeys)
            : [];
        $activeAccess = $userIds === [] ? [] : $this->activeAccessByUser($tenantId, $facilityId, $userIds);
        $employments = $this->latestEmployments($tenantId, $staffIds);

        $rows = [];
        foreach ($staffRows as $staffRow) {
            $staffId = (string) $staffRow->id;
            $userId = $staffRow->linked_user_id === null ? null : (string) $staffRow->linked_user_id;
            $assignment = $assignments[$staffId] ?? null;
            $pendingPlacement = $pendingPlacements[$staffId] ?? null;
            $rejectedPlacement = $rejectedPlacements[$staffId] ?? null;
            $placement = $assignment ?? $pendingPlacement ?? $rejectedPlacement;
            $invitation = $userId === null ? null : ($invitations[$userId] ?? null);
            $accessRoles = $userId === null ? [] : ($pendingAccess[$userId] ?? []);
            $employment = $employments[$staffId] ?? null;
            $accountState = $this->accountState(
                $userId,
                $staffRow->account_verified_at === null ? null : (string) $staffRow->account_verified_at,
                $invitation,
            );

            $rows[] = [
                'id' => $staffId,
                'staffNumber' => (string) $staffRow->staff_number,
                'name' => trim(implode(' ', array_filter([
                    $staffRow->first_name,
                    $staffRow->middle_name,
                    $staffRow->last_name,
                ]))),
                'staffStatus' => (string) $staffRow->staff_status,
                'positionTitle' => $assignment['positionTitle'] ?? null,
                'linkedUserId' => $userId,
                'accountEmail' => $staffRow->account_email === null ? null : (string) $staffRow->account_email,
                'accountVerifiedAt' => $staffRow->account_verified_at === null
                    ? null
                    : (string) $staffRow->account_verified_at,
                'placement' => [
                    'state' => $assignment !== null
                        ? 'CONFIRMED'
                        : ($pendingPlacement !== null ? 'PENDING' : ($rejectedPlacement !== null ? 'REJECTED' : 'NONE')),
                    'requestId' => $pendingPlacement['requestId'] ?? $rejectedPlacement['requestId'] ?? null,
                    'departmentName' => $placement['departmentName'] ?? null,
                    'unitName' => $placement['unitName'] ?? null,
                    'effectiveFrom' => $placement['effectiveFrom'] ?? null,
                    'decisionReason' => $rejectedPlacement['decisionReason'] ?? null,
                ],
                'accountState' => $accountState,
                'invitation' => [
                    'id' => $invitation['id'] ?? null,
                    'status' => $invitation['status'] ?? null,
                    'expiresAt' => $invitation['expiresAt'] ?? null,
                    'roleKeys' => $invitation['roleKeys'] ?? [],
                ],
                'access' => [
                    'state' => $accessRoles !== []
                        ? 'PENDING'
                        : (($userId !== null && isset($activeAccess[$userId])) ? 'ACTIVE' : ($accountState === 'ACTIVE' ? 'NONE' : 'WAITING')),
                    'pendingCount' => count($accessRoles),
                    'roleKeys' => $accessRoles,
                ],
                'employment' => $this->employmentState($employment, $today),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<string>  $staffIds
     * @return array<string, array{
     *     departmentName: string|null,
     *     unitName: string|null,
     *     effectiveFrom: string,
     *     positionTitle: string|null
     * }>
     */
    private function currentAssignments(
        string $tenantId,
        string $facilityId,
        string $today,
        array $staffIds,
    ): array {
        $rows = DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->leftJoin('org_departments as department', 'department.id', '=', 'assignment.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'assignment.unit_id')
            ->leftJoin('hr_positions as position', 'position.id', '=', 'staff.primary_position_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->whereIn('assignment.staff_id', $staffIds)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>=', $today))
            ->orderByDesc('assignment.effective_from')
            ->get([
                'assignment.staff_id',
                'assignment.effective_from',
                'department.name as department_name',
                'unit.name as unit_name',
                'position.title as position_title',
            ]);

        $assignments = [];
        foreach ($rows as $row) {
            $staffId = (string) $row->staff_id;
            if (isset($assignments[$staffId])) {
                continue;
            }

            $assignments[$staffId] = [
                'departmentName' => $row->department_name === null ? null : (string) $row->department_name,
                'unitName' => $row->unit_name === null ? null : (string) $row->unit_name,
                'effectiveFrom' => (string) $row->effective_from,
                'positionTitle' => $row->position_title === null ? null : (string) $row->position_title,
            ];
        }

        return $assignments;
    }

    /**
     * @param  list<string>  $staffIds
     * @return array<string, array{
     *     requestId: string,
     *     departmentName: string|null,
     *     unitName: string|null,
     *     effectiveFrom: string,
     *     decisionReason: string|null
     * }>
     */
    private function placements(
        string $tenantId,
        string $facilityId,
        array $staffIds,
        string $status,
        ?string $requestedBy = null,
    ): array {
        $rows = DB::table('hr_assignment_requests as request')
            ->leftJoin('org_departments as department', 'department.id', '=', 'request.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'request.unit_id')
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->whereIn('request.staff_id', $staffIds)
            ->where('request.status', $status)
            ->when($requestedBy !== null, fn ($query) => $query->where('request.requested_by', $requestedBy))
            ->orderByDesc('request.created_at')
            ->get([
                'request.id',
                'request.staff_id',
                'request.effective_from',
                'request.decision_reason',
                'department.name as department_name',
                'unit.name as unit_name',
            ]);

        $placements = [];
        foreach ($rows as $row) {
            $staffId = (string) $row->staff_id;
            if (isset($placements[$staffId])) {
                continue;
            }

            $placements[$staffId] = [
                'requestId' => (string) $row->id,
                'departmentName' => $row->department_name === null ? null : (string) $row->department_name,
                'unitName' => $row->unit_name === null ? null : (string) $row->unit_name,
                'effectiveFrom' => (string) $row->effective_from,
                'decisionReason' => $row->decision_reason === null ? null : (string) $row->decision_reason,
            ];
        }

        return $placements;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, array{id: string, status: string, expiresAt: string, roleKeys: list<string>}>
     */
    private function latestInvitations(string $tenantId, string $facilityId, array $userIds): array
    {
        $rows = DB::table('aut_admin_bootstrap_tokens as invitation')
            ->where('invitation.tenant_id', $tenantId)
            ->where('invitation.facility_id', $facilityId)
            ->whereIn('invitation.user_id', $userIds)
            ->where('invitation.purpose', 'FACILITY_ROLE')
            ->orderByDesc('invitation.created_at')
            ->orderByDesc('invitation.id')
            ->get([
                'invitation.id',
                'invitation.user_id',
                'invitation.status',
                'invitation.expires_at',
                'invitation.role_key',
                'invitation.role_keys',
            ]);

        $invitations = [];
        foreach ($rows as $row) {
            $userId = (string) $row->user_id;
            if (isset($invitations[$userId])) {
                continue;
            }

            $invitations[$userId] = [
                'id' => (string) $row->id,
                'status' => (string) $row->status,
                'expiresAt' => (string) $row->expires_at,
                'roleKeys' => $this->decodeRoleKeys($row->role_key, $row->role_keys),
            ];
        }

        return $invitations;
    }

    /**
     * @param  list<string>  $userIds
     * @param  list<string>  $roleKeys
     * @return array<string, list<string>>
     */
    private function pendingAccessByUser(
        string $tenantId,
        string $facilityId,
        string $actorId,
        array $userIds,
        array $roleKeys,
    ): array {
        if ($roleKeys === []) {
            return [];
        }

        $rows = DB::table('idn_admin_role_promotion_requests as request')
            ->join('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'request.target_user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->whereIn('request.target_user_id', $userIds)
            ->where('request.requested_by', $actorId)
            ->where('request.request_origin', 'FACILITY_INVITATION')
            ->where('request.action', 'PROMOTE')
            ->where('request.status', 'PENDING')
            ->whereIn('request.role_key', $roleKeys)
            ->where('account.status', 'ACTIVE')
            ->whereNotNull('account.email_verified_at')
            ->orderBy('request.created_at')
            ->get(['request.target_user_id', 'request.role_key']);

        $access = [];
        foreach ($rows as $row) {
            $userId = (string) $row->target_user_id;
            $access[$userId][] = (string) $row->role_key;
        }

        return $access;
    }

    /**
     * @param  list<string>  $userIds
     * @return array<string, true>
     */
    private function activeAccessByUser(string $tenantId, string $facilityId, array $userIds): array
    {
        $rows = DB::table('idn_access_assignments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->whereIn('user_id', $userIds)
            ->where('status', 'ACTIVE')
            ->distinct()
            ->pluck('user_id');

        $access = [];
        foreach ($rows as $userId) {
            $access[(string) $userId] = true;
        }

        return $access;
    }

    /**
     * @param  list<string>  $staffIds
     * @return array<string, array{status: string, startDate: string|null}>
     */
    private function latestEmployments(string $tenantId, array $staffIds): array
    {
        $rows = DB::table('hr_employments')
            ->where('tenant_id', $tenantId)
            ->whereIn('staff_id', $staffIds)
            ->orderByDesc('created_at')
            ->get(['staff_id', 'status', 'start_date']);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[(string) $row->staff_id][] = [
                'status' => (string) $row->status,
                'startDate' => $row->start_date === null ? null : (string) $row->start_date,
            ];
        }

        $employments = [];
        foreach ($grouped as $staffId => $rowsForStaff) {
            $active = null;
            $draft = null;
            $ended = null;
            foreach ($rowsForStaff as $employment) {
                if ($employment['status'] === 'ACTIVE') {
                    $active = $employment;
                    break;
                }
                if ($employment['status'] === 'DRAFT' && $draft === null) {
                    $draft = $employment;
                }
                if ($employment['status'] === 'ENDED' && $ended === null) {
                    $ended = $employment;
                }
            }

            $employments[$staffId] = $active ?? $draft ?? $ended ?? $rowsForStaff[0];
        }

        return $employments;
    }

    /** @param array{status: string, startDate: string|null}|null $employment
     * @return array{state: string, status: string|null, startDate: string|null}
     */
    private function employmentState(?array $employment, string $today): array
    {
        if ($employment === null) {
            return ['state' => 'NONE', 'status' => null, 'startDate' => null];
        }

        $state = match ($employment['status']) {
            'ACTIVE' => 'ACTIVE',
            'DRAFT' => $employment['startDate'] === null || $employment['startDate'] <= $today
                ? 'DRAFT_DUE'
                : 'DRAFT_FUTURE',
            'SUSPENDED' => 'SUSPENDED',
            'ENDED' => 'ENDED',
            default => 'UNKNOWN',
        };

        return [
            'state' => $state,
            'status' => $employment['status'],
            'startDate' => $employment['startDate'],
        ];
    }

    /** @param array{id: string, status: string, expiresAt: string, roleKeys: list<string>}|null $invitation */
    private function accountState(?string $userId, ?string $verifiedAt, ?array $invitation): string
    {
        if ($userId === null) {
            return 'NO_ACCOUNT';
        }
        if ($verifiedAt !== null) {
            return 'ACTIVE';
        }

        $status = $invitation['status'] ?? null;
        if ($status === 'PENDING'
            && $invitation !== null
            && now()->lt($invitation['expiresAt'])) {
            return 'INVITED';
        }
        if (in_array($status, ['PENDING', 'EXPIRED'], true)) {
            return 'INVITATION_EXPIRED';
        }
        if ($status === 'REVOKED') {
            return 'INVITATION_REVOKED';
        }
        if ($status === 'SUPERSEDED') {
            return 'INVITATION_REPLACED';
        }

        return 'SETUP_PENDING';
    }

    private function pendingPlacementExists(string $tenantId, string $facilityId): Builder
    {
        return DB::table('hr_assignment_requests as pending_placement')
            ->whereColumn('pending_placement.staff_id', 'staff.id')
            ->where('pending_placement.tenant_id', $tenantId)
            ->where('pending_placement.facility_id', $facilityId)
            ->where('pending_placement.status', 'PENDING');
    }

    private function openInvitationExists(string $tenantId, string $facilityId): Builder
    {
        return DB::table('aut_admin_bootstrap_tokens as open_invitation')
            ->join('users as open_invitation_account', function ($join) use ($tenantId): void {
                $join->on('open_invitation_account.id', '=', 'open_invitation.user_id')
                    ->where('open_invitation_account.tenant_id', '=', $tenantId);
            })
            ->whereColumn('open_invitation.user_id', 'account.id')
            ->where('open_invitation.tenant_id', $tenantId)
            ->where('open_invitation.facility_id', $facilityId)
            ->where('open_invitation.purpose', 'FACILITY_ROLE')
            ->whereNull('open_invitation.consumed_at')
            ->whereNull('open_invitation_account.email_verified_at')
            ->whereIn('open_invitation.status', ['PENDING', 'EXPIRED']);
    }

    /** @param list<string> $roleKeys */
    private function pendingAccessExists(
        string $tenantId,
        string $facilityId,
        string $actorId,
        array $roleKeys,
    ): Builder {
        return DB::table('idn_admin_role_promotion_requests as pending_access')
            ->whereColumn('pending_access.target_user_id', 'account.id')
            ->where('pending_access.tenant_id', $tenantId)
            ->where('pending_access.facility_id', $facilityId)
            ->where('pending_access.requested_by', $actorId)
            ->where('pending_access.request_origin', 'FACILITY_INVITATION')
            ->where('pending_access.action', 'PROMOTE')
            ->where('pending_access.status', 'PENDING')
            ->where('account.status', 'ACTIVE')
            ->whereNotNull('account.email_verified_at')
            ->whereIn('pending_access.role_key', $roleKeys);
    }

    /** @return list<string> */
    private function decodeRoleKeys(mixed $roleKey, mixed $roleKeys): array
    {
        $decoded = is_string($roleKeys) ? json_decode($roleKeys, true) : null;
        if (is_array($decoded)) {
            return array_values(array_filter($decoded, static fn (mixed $key): bool => is_string($key)));
        }

        return is_string($roleKey) && $roleKey !== '' ? [$roleKey] : [];
    }
}
