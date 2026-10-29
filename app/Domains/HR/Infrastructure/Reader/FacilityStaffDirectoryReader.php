<?php

declare(strict_types=1);

namespace App\Domains\HR\Infrastructure\Reader;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class FacilityStaffDirectoryReader
{
    public function activeCadreExists(string $tenantId, string $cadreId): bool
    {
        return DB::table('hr_cadres')
            ->where('tenant_id', $tenantId)
            ->where('id', $cadreId)
            ->where('is_active', true)
            ->exists();
    }

    /** @return array{status: string, primaryCadreId: ?string, primaryPositionId: ?string}|null */
    public function onboardableStaff(string $tenantId, string $facilityId, string $staffId, string $today): ?array
    {
        $row = DB::table('hr_staff')
            ->join('hr_assignments as assignment', 'assignment.staff_id', '=', 'hr_staff.id')
            ->where('hr_staff.tenant_id', $tenantId)
            ->where('hr_staff.id', $staffId)
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $today))
            ->first([
                'hr_staff.status',
                'hr_staff.primary_cadre_id',
                'hr_staff.primary_position_id',
            ]);

        return $row === null ? null : [
            'status' => (string) $row->status,
            'primaryCadreId' => $row->primary_cadre_id === null ? null : (string) $row->primary_cadre_id,
            'primaryPositionId' => $row->primary_position_id === null ? null : (string) $row->primary_position_id,
        ];
    }

    /**
     * The earliest draft employment that is due to start on or before the
     * confirmed joined date. Returns null when the draft is future-dated or
     * when an active employment already exists.
     */
    public function startableDraftEmploymentId(string $tenantId, string $staffId, string $joinedDate): ?string
    {
        $hasActiveEmployment = DB::table('hr_employments')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->where('status', 'ACTIVE')
            ->exists();

        if ($hasActiveEmployment) {
            return null;
        }

        $employmentId = DB::table('hr_employments')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->where('status', 'DRAFT')
            ->where(fn ($query) => $query
                ->whereNull('start_date')
                ->orWhereDate('start_date', '<=', $joinedDate))
            ->orderBy('start_date')
            ->orderBy('created_at')
            ->value('id');

        return is_string($employmentId) ? $employmentId : null;
    }

    public function canStartEmployment(
        string $tenantId,
        string $facilityId,
        string $staffId,
        string $employmentId,
        string $today,
    ): bool {
        return DB::table('hr_employments as employment')
            ->join('hr_staff as staff', 'staff.id', '=', 'employment.staff_id')
            ->where('employment.tenant_id', $tenantId)
            ->where('employment.id', $employmentId)
            ->where('employment.staff_id', $staffId)
            ->where('employment.status', 'DRAFT')
            ->where('staff.status', 'ACTIVE')
            ->whereExists(fn ($query) => $query->selectRaw('1')
                ->from('hr_assignments as assignment')
                ->whereColumn('assignment.staff_id', 'staff.id')
                ->where('assignment.tenant_id', $tenantId)
                ->where('assignment.facility_id', $facilityId)
                ->where('assignment.status', 'ACTIVE')
                ->where('assignment.effective_from', '<=', $today)
                ->where(fn ($dates) => $dates->whereNull('assignment.effective_to')
                    ->orWhere('assignment.effective_to', '>=', $today)))
            ->exists();
    }

    public function editablePersonId(string $tenantId, string $facilityId, string $staffId, string $today): ?string
    {
        $personId = DB::table('hr_staff as staff')
            ->join('hr_assignments as assignment', 'assignment.staff_id', '=', 'staff.id')
            ->where('staff.tenant_id', $tenantId)
            ->where('staff.id', $staffId)
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $today))
            ->value('staff.person_id');

        return is_string($personId) ? $personId : null;
    }

    public function hasFacilityAssignment(string $tenantId, string $facilityId, string $staffId): bool
    {
        return DB::table('hr_assignments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('staff_id', $staffId)
            ->exists();
    }

    public function linkedUserId(string $tenantId, string $staffId): ?string
    {
        $userId = DB::table('hr_staff_user_links')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->value('user_id');

        return is_string($userId) ? $userId : null;
    }

    public function isEligibleForAccountLink(string $tenantId, string $facilityId, string $staffId, string $today): bool
    {
        return DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.staff_id', $staffId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $today))
            ->whereIn('staff.status', ['PROSPECT', 'ACTIVE'])
            ->exists();
    }

    /** @return array{id: string, firstName: string, middleName: ?string, lastName: string}|null */
    public function staffEligibleForInvitation(string $tenantId, string $facilityId, string $staffId, string $today): ?array
    {
        $row = DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.staff_id', $staffId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>=', $today))
            ->whereIn('staff.status', ['PROSPECT', 'ACTIVE'])
            ->whereNull('staff_link.user_id')
            ->first(['staff.id', 'person.first_name', 'person.middle_name', 'person.last_name']);

        return $row === null ? null : [
            'id' => (string) $row->id,
            'firstName' => (string) $row->first_name,
            'middleName' => $row->middle_name === null ? null : (string) $row->middle_name,
            'lastName' => (string) $row->last_name,
        ];
    }

    /** @return array{id: string, cadreId: string}|null */
    public function activePosition(string $tenantId, string $positionId): ?array
    {
        $position = DB::table('hr_positions')
            ->where('tenant_id', $tenantId)
            ->where('id', $positionId)
            ->where('is_active', true)
            ->first(['id', 'cadre_id']);

        return $position === null
            ? null
            : ['id' => (string) $position->id, 'cadreId' => (string) $position->cadre_id];
    }

    /**
     * @return list<array{id: string, code: string, title: string, cadreId: string}>
     */
    public function activePositions(string $tenantId): array
    {
        return array_values(DB::table('hr_positions as position')
            ->join('hr_cadres as cadre', function ($join): void {
                $join->on('cadre.id', '=', 'position.cadre_id')
                    ->on('cadre.tenant_id', '=', 'position.tenant_id');
            })
            ->where('position.tenant_id', $tenantId)
            ->where('position.is_active', true)
            ->orderBy('position.title')
            ->get(['position.id', 'position.code', 'position.title', 'position.cadre_id'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'title' => (string) $row->title,
                'cadreId' => (string) $row->cadre_id,
            ])->all());
    }

    /** @return list<array{id: string, code: string, name: string, category: string}> */
    public function activeCadres(string $tenantId): array
    {
        return array_values(DB::table('hr_cadres')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'category'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'category' => (string) $row->category,
            ])->all());
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     scope: string,
     *     departmentName: ?string,
     *     unitName: ?string,
     *     effectiveFrom: string
     * }>
     */
    public function pendingAssignmentRequests(string $tenantId, string $facilityId): array
    {
        return array_values(DB::table('hr_assignment_requests as request')
            ->join('hr_staff as staff', 'staff.id', '=', 'request.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('org_departments as department', 'department.id', '=', 'request.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'request.unit_id')
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->where('request.status', 'PENDING')
            ->orderBy('request.created_at')
            ->get([
                'request.id',
                'request.scope',
                'request.effective_from',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'staff.staff_number',
                'department.name as department_name',
                'unit.name as unit_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
                'scope' => (string) $row->scope,
                'departmentName' => $row->department_name,
                'unitName' => $row->unit_name,
                'effectiveFrom' => (string) $row->effective_from,
            ])->all());
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     departmentId: ?string,
     *     unitId: ?string,
     *     departmentName: ?string,
     *     unitName: ?string,
     *     effectiveFrom: string,
     *     decisionReason: ?string
     * }>
     */
    public function rejectedAssignmentRequests(string $tenantId, string $facilityId, string $actorId): array
    {
        return array_values(DB::table('hr_assignment_requests as request')
            ->join('hr_staff as staff', 'staff.id', '=', 'request.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('org_departments as department', 'department.id', '=', 'request.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'request.unit_id')
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->where('request.status', 'REJECTED')
            ->where('request.requested_by', $actorId)
            ->orderByDesc('request.updated_at')
            ->get([
                'request.id',
                'request.department_id',
                'request.unit_id',
                'request.effective_from',
                'request.decision_reason',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'staff.staff_number',
                'department.name as department_name',
                'unit.name as unit_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name]))),
                'departmentId' => $row->department_id === null ? null : (string) $row->department_id,
                'unitId' => $row->unit_id === null ? null : (string) $row->unit_id,
                'departmentName' => $row->department_name,
                'unitName' => $row->unit_name,
                'effectiveFrom' => (string) $row->effective_from,
                'decisionReason' => $row->decision_reason,
            ])->all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function staffProfile(string $tenantId, string $facilityId, string $staffId, string $today): ?array
    {
        $row = DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as linked_user', function ($join) use ($tenantId): void {
                $join->on('linked_user.id', '=', 'staff_link.user_id')
                    ->where('linked_user.tenant_id', '=', $tenantId);
            })
            ->leftJoin('org_departments as department', 'department.id', '=', 'assignment.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'assignment.unit_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('staff.id', $staffId)
            ->orderByRaw(
                "CASE WHEN assignment.status = 'ACTIVE' AND assignment.effective_from <= ? AND (assignment.effective_to IS NULL OR assignment.effective_to >= ?) THEN 0 ELSE 1 END",
                [$today, $today],
            )
            ->orderByDesc('assignment.effective_from')
            ->select([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'staff.joined_date',
                'staff.suspension_reason',
                'staff.terminated_date',
                'staff.termination_reason',
                'staff.person_id',
                'staff.primary_position_id',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.date_of_birth',
                'person.gender',
                'person.national_id',
                'person.phone',
                'person.email',
                'person.physical_address',
                'person.photo_document_id',
                'linked_user.id as linked_user_id',
                'linked_user.email as linked_user_email',
                'assignment.scope as assignment_scope',
                'assignment.department_id',
                'assignment.unit_id',
                'department.name as department_name',
                'unit.name as unit_name',
                DB::raw('NULL as pending_assignment_request_id'),
                DB::raw('NULL as pending_assignment_effective_from'),
            ])
            ->selectSub(
                DB::table('hr_positions')
                    ->select('title')
                    ->whereColumn('id', 'staff.primary_position_id')
                    ->limit(1),
                'position_title',
            )
            ->first();

        if ($row !== null) {
            return (array) $row;
        }

        $pendingRow = DB::table('hr_assignment_requests as request')
            ->join('hr_staff as staff', 'staff.id', '=', 'request.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as linked_user', function ($join) use ($tenantId): void {
                $join->on('linked_user.id', '=', 'staff_link.user_id')
                    ->where('linked_user.tenant_id', '=', $tenantId);
            })
            ->leftJoin('org_departments as department', 'department.id', '=', 'request.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'request.unit_id')
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->where('request.staff_id', $staffId)
            ->where('request.status', 'PENDING')
            ->select([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'staff.joined_date',
                'staff.suspension_reason',
                'staff.terminated_date',
                'staff.termination_reason',
                'staff.person_id',
                'staff.primary_position_id',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.date_of_birth',
                'person.gender',
                'person.national_id',
                'person.phone',
                'person.email',
                'person.physical_address',
                'person.photo_document_id',
                'linked_user.id as linked_user_id',
                'linked_user.email as linked_user_email',
                DB::raw("'PENDING' as assignment_scope"),
                'request.department_id',
                'request.unit_id',
                'department.name as department_name',
                'unit.name as unit_name',
                'request.id as pending_assignment_request_id',
                'request.effective_from as pending_assignment_effective_from',
            ])
            ->selectSub(
                DB::table('hr_positions')
                    ->select('title')
                    ->whereColumn('id', 'staff.primary_position_id')
                    ->limit(1),
                'position_title',
            )
            ->first();

        return $pendingRow === null ? null : (array) $pendingRow;
    }

    public function hasCurrentFacilityAssignment(string $tenantId, string $facilityId, string $staffId, string $today): bool
    {
        return DB::table('hr_assignments')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('staff_id', $staffId)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->exists();
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<array{id: string, roleKey: string}>
     */
    public function pendingInvitationAccessRequests(
        string $tenantId,
        string $facilityId,
        string $userId,
        string $actorId,
        array $roleKeys,
    ): array {
        return array_values(DB::table('idn_admin_role_promotion_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('target_user_id', $userId)
            ->where('requested_by', $actorId)
            ->where('request_origin', 'FACILITY_INVITATION')
            ->where('action', 'PROMOTE')
            ->where('status', 'PENDING')
            ->whereIn('role_key', $roleKeys)
            ->orderBy('created_at')
            ->get(['id', 'role_key'])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'roleKey' => (string) $row->role_key,
            ])->all());
    }

    /**
     * @param  list<string>  $roleKeys
     * @return list<array{
     *     id: string,
     *     staffId: string,
     *     staffNumber: string,
     *     staffName: string,
     *     accountEmail: string,
     *     roleKey: string,
     *     reason: string,
     *     requestedAt: string,
     *     requesterName: string
     * }>
     */
    public function pendingFacilityInvitationRoleApprovals(
        string $tenantId,
        string $facilityId,
        string $actorId,
        array $roleKeys,
    ): array {
        return array_values(DB::table('idn_admin_role_promotion_requests as request')
            ->join('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.user_id', '=', 'request.target_user_id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->join('hr_staff as staff', function ($join) use ($tenantId): void {
                $join->on('staff.id', '=', 'staff_link.staff_id')
                    ->where('staff.tenant_id', '=', $tenantId);
            })
            ->join('hr_persons as person', function ($join) use ($tenantId): void {
                $join->on('person.id', '=', 'staff.person_id')
                    ->where('person.tenant_id', '=', $tenantId);
            })
            ->join('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'request.target_user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->join('users as requester', function ($join) use ($tenantId): void {
                $join->on('requester.id', '=', 'request.requested_by')
                    ->where('requester.tenant_id', '=', $tenantId);
            })
            ->where('request.tenant_id', $tenantId)
            ->where('request.facility_id', $facilityId)
            ->where('request.requested_by', $actorId)
            ->where('request.request_origin', 'FACILITY_INVITATION')
            ->where('request.action', 'PROMOTE')
            ->where('request.status', 'PENDING')
            ->whereIn('request.role_key', $roleKeys)
            ->where('account.status', 'ACTIVE')
            ->whereNotNull('account.email_verified_at')
            ->orderBy('request.created_at')
            ->get([
                'request.id',
                'staff.id as staff_id',
                'staff.staff_number',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'account.email',
                'request.role_key',
                'request.reason',
                'request.created_at',
                'requester.name as requester_name',
            ])
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffId' => (string) $row->staff_id,
                'staffNumber' => (string) $row->staff_number,
                'staffName' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))),
                'accountEmail' => (string) $row->email,
                'roleKey' => (string) $row->role_key,
                'reason' => (string) $row->reason,
                'requestedAt' => CarbonImmutable::parse((string) $row->created_at, 'UTC')->toIso8601String(),
                'requesterName' => (string) $row->requester_name,
            ])->all());
    }

    /** @return array<string, mixed>|null */
    public function latestEmployment(string $tenantId, string $staffId): ?array
    {
        $row = DB::table('hr_employments')
            ->where('tenant_id', $tenantId)
            ->where('staff_id', $staffId)
            ->orderByDesc('created_at')
            ->first([
                'id',
                'contract_type',
                'status',
                'start_date',
                'fte',
                'end_date',
                'probation_end_date',
                'terms_summary',
            ]);

        return $row === null ? null : (array) $row;
    }

    /** @return array{activeAssignments: int, openEmployments: int, futurePublishedShifts: int} */
    public function terminationBlockers(string $tenantId, string $staffId, string $today): array
    {
        return [
            'activeAssignments' => DB::table('hr_assignments')
                ->where('tenant_id', $tenantId)
                ->where('staff_id', $staffId)
                ->where('status', 'ACTIVE')
                ->count(),
            'openEmployments' => DB::table('hr_employments')
                ->where('tenant_id', $tenantId)
                ->where('staff_id', $staffId)
                ->whereIn('status', ['ACTIVE', 'SUSPENDED'])
                ->count(),
            'futurePublishedShifts' => DB::table('hr_roster_entries')
                ->where('tenant_id', $tenantId)
                ->where('staff_id', $staffId)
                ->where('status', 'PUBLISHED')
                ->where('date', '>=', $today)
                ->count(),
        ];
    }

    /**
     * Paginated facility directory, filtered by the staff lifecycle status.
     *
     * "Active staff" means an ACTIVE staff record with a current active
     * assignment — not merely an active assignment attached to a PROSPECT or
     * SUSPENDED record. The account state is derived from the latest setup
     * invitation so the directory never implies that an invited person has an
     * activated account.
     *
     * @return LengthAwarePaginator<int|string, array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: string|null,
     *     email: string|null,
     *     positionTitle: string|null,
     *     departmentName: string|null,
     *     unitName: string|null,
     *     linkedUserId: string|null,
     *     linkedUserEmail: string|null,
     *     accountState: string,
     *     invitationStatus: string|null,
     *     invitationExpiresAt: string|null,
     *     facilityId: string
     * }>
     */
    public function staffDirectory(
        string $tenantId,
        string $facilityId,
        string $today,
        string $status = 'ACTIVE',
        string $search = '',
        int $page = 1,
        int $perPage = 25,
    ): LengthAwarePaginator {
        $query = $this->currentAssignmentQuery($tenantId, $facilityId, $today)
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('org_departments as department', 'department.id', '=', 'assignment.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'assignment.unit_id')
            ->leftJoin('hr_positions as position', 'position.id', '=', 'staff.primary_position_id')
            ->leftJoin('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->leftJoin('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'staff_link.user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            });

        if ($status !== 'ALL') {
            $query->where('staff.status', $status);
        }

        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($matches) use ($like): void {
                $matches->whereRaw('LOWER(person.first_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(person.middle_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(person.last_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(staff.staff_number) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(person.phone, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(person.email, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(department.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(unit.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(position.title, \'\')) LIKE ?', [$like]);
            });
        }

        $query->orderBy('person.last_name')
            ->orderBy('person.first_name')
            ->select([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.phone',
                'person.email',
                'department.name as department_name',
                'unit.name as unit_name',
                'position.title as position_title',
                'account.id as linked_user_id',
                'account.email as linked_user_email',
                'account.email_verified_at',
            ])
            ->selectSub(
                DB::table('aut_admin_bootstrap_tokens as invitation')
                    ->select('invitation.status')
                    ->whereColumn('invitation.user_id', 'account.id')
                    ->where('invitation.tenant_id', $tenantId)
                    ->where('invitation.facility_id', $facilityId)
                    ->where('invitation.purpose', 'FACILITY_ROLE')
                    ->orderByDesc('invitation.created_at')
                    ->orderByDesc('invitation.id')
                    ->limit(1),
                'invitation_status',
            )
            ->selectSub(
                DB::table('aut_admin_bootstrap_tokens as invitation')
                    ->select('invitation.expires_at')
                    ->whereColumn('invitation.user_id', 'account.id')
                    ->where('invitation.tenant_id', $tenantId)
                    ->where('invitation.facility_id', $facilityId)
                    ->where('invitation.purpose', 'FACILITY_ROLE')
                    ->orderByDesc('invitation.created_at')
                    ->orderByDesc('invitation.id')
                    ->limit(1),
                'invitation_expires_at',
            );

        return $query->paginate(perPage: $perPage, page: $page)
            ->through(function (object $row) use ($facilityId): array {
                $row = (array) $row;
                $accountState = $this->accountState($row);
                $invitationStatus = $row['invitation_status'] === null
                    ? null
                    : (string) $row['invitation_status'];

                return [
                    'id' => (string) $row['id'],
                    'staffNumber' => (string) $row['staff_number'],
                    'name' => trim(implode(' ', array_filter([
                        $row['first_name'],
                        $row['middle_name'],
                        $row['last_name'],
                    ]))),
                    'status' => (string) $row['staff_status'],
                    'phone' => $row['phone'] === null ? null : (string) $row['phone'],
                    'email' => $row['email'] === null ? null : (string) $row['email'],
                    'positionTitle' => $row['position_title'] === null ? null : (string) $row['position_title'],
                    'departmentName' => $row['department_name'] === null ? null : (string) $row['department_name'],
                    'unitName' => $row['unit_name'] === null ? null : (string) $row['unit_name'],
                    'linkedUserId' => $row['linked_user_id'] === null ? null : (string) $row['linked_user_id'],
                    'linkedUserEmail' => $row['linked_user_email'] === null ? null : (string) $row['linked_user_email'],
                    'accountState' => $accountState,
                    'invitationStatus' => $invitationStatus,
                    'invitationExpiresAt' => $row['invitation_expires_at'] === null
                        ? null
                        : (string) $row['invitation_expires_at'],
                    'facilityId' => $facilityId,
                ];
            });
    }

    /**
     * @return array<string, int>
     */
    public function staffStatusCounts(string $tenantId, string $facilityId, string $today): array
    {
        $counts = $this->currentAssignmentQuery($tenantId, $facilityId, $today)
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->selectRaw('staff.status, COUNT(DISTINCT staff.id) AS aggregate')
            ->groupBy('staff.status')
            ->pluck('aggregate', 'staff.status')
            ->mapWithKeys(static fn (mixed $count, mixed $status): array => [(string) $status => (int) $count])
            ->all();

        $all = 0;
        foreach ($counts as $count) {
            $all += $count;
        }

        return ['ALL' => $all, ...$counts];
    }

    /**
     * Count only staff whose own lifecycle status is ACTIVE. This is the
     * headcount the dashboard should show.
     */
    public function activeStaffCount(string $tenantId, string $facilityId, string $today): int
    {
        return $this->currentAssignmentQuery($tenantId, $facilityId, $today)
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->where('staff.status', 'ACTIVE')
            ->distinct('assignment.staff_id')
            ->count('assignment.staff_id');
    }

    public function expiredInvitationCount(string $tenantId, string $facilityId): int
    {
        return DB::table('aut_admin_bootstrap_tokens as invitation')
            ->join('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'invitation.user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->where('invitation.tenant_id', $tenantId)
            ->where('invitation.facility_id', $facilityId)
            ->where('invitation.purpose', 'FACILITY_ROLE')
            ->whereNull('invitation.consumed_at')
            ->whereNull('account.email_verified_at')
            ->where(function ($expired): void {
                $expired->where('invitation.status', 'EXPIRED')
                    ->orWhere(function ($lapsed): void {
                        $lapsed->where('invitation.status', 'PENDING')
                            ->where('invitation.expires_at', '<=', now());
                    });
            })
            ->count();
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: ?string,
     *     email: ?string,
     *     positionTitle: ?string,
     *     departmentName: ?string,
     *     unitName: ?string
     * }>
     */
    public function activeStaff(string $tenantId, string $facilityId, string $today): array
    {
        return array_values(DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->leftJoin('org_departments as department', 'department.id', '=', 'assignment.department_id')
            ->leftJoin('org_units as unit', 'unit.id', '=', 'assignment.unit_id')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>=', $today))
            ->where('staff.status', 'ACTIVE')
            ->orderBy('person.last_name')
            ->orderBy('person.first_name')
            ->select([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.phone',
                'person.email',
                'department.name as department_name',
                'unit.name as unit_name',
            ])
            ->selectSub(
                DB::table('hr_positions')
                    ->select('title')
                    ->whereColumn('id', 'staff.primary_position_id')
                    ->limit(1),
                'position_title',
            )
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))),
                'status' => (string) $row->staff_status,
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'email' => $row->email === null ? null : (string) $row->email,
                'positionTitle' => $row->position_title === null ? null : (string) $row->position_title,
                'departmentName' => $row->department_name === null ? null : (string) $row->department_name,
                'unitName' => $row->unit_name === null ? null : (string) $row->unit_name,
            ])->all());
    }

    /**
     * @return list<array{
     *     id: string,
     *     staffNumber: string,
     *     name: string,
     *     status: string,
     *     phone: ?string,
     *     email: string,
     *     positionTitle: ?string
     * }>
     */
    public function expiredSetupInvitations(string $tenantId, string $facilityId, string $today): array
    {
        return array_values(DB::table('hr_assignments as assignment')
            ->join('hr_staff as staff', 'staff.id', '=', 'assignment.staff_id')
            ->join('hr_persons as person', 'person.id', '=', 'staff.person_id')
            ->join('hr_staff_user_links as staff_link', function ($join) use ($tenantId): void {
                $join->on('staff_link.staff_id', '=', 'staff.id')
                    ->where('staff_link.tenant_id', '=', $tenantId);
            })
            ->join('users as account', function ($join) use ($tenantId): void {
                $join->on('account.id', '=', 'staff_link.user_id')
                    ->where('account.tenant_id', '=', $tenantId);
            })
            ->join('aut_admin_bootstrap_tokens as invitation', function ($join) use ($tenantId, $facilityId): void {
                $join->on('invitation.user_id', '=', 'account.id')
                    ->where('invitation.tenant_id', '=', $tenantId)
                    ->where('invitation.facility_id', '=', $facilityId)
                    ->where('invitation.purpose', '=', 'FACILITY_ROLE');
            })
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>=', $today))
            ->whereIn('staff.status', ['PROSPECT', 'ACTIVE'])
            ->where('account.status', 'ACTIVE')
            ->whereNull('account.email_verified_at')
            ->whereNull('invitation.consumed_at')
            ->where('invitation.expires_at', '<=', now())
            ->whereNotExists(function ($query) use ($tenantId, $facilityId): void {
                $query->selectRaw('1')
                    ->from('aut_admin_bootstrap_tokens as newer_invitation')
                    ->whereColumn('newer_invitation.user_id', 'invitation.user_id')
                    ->where('newer_invitation.tenant_id', $tenantId)
                    ->where('newer_invitation.facility_id', $facilityId)
                    ->where('newer_invitation.purpose', 'FACILITY_ROLE')
                    ->whereNull('newer_invitation.consumed_at')
                    ->whereColumn('newer_invitation.created_at', '>', 'invitation.created_at');
            })
            ->orderBy('person.last_name')
            ->orderBy('person.first_name')
            ->select([
                'staff.id',
                'staff.staff_number',
                'staff.status as staff_status',
                'person.first_name',
                'person.middle_name',
                'person.last_name',
                'person.phone',
                'account.email',
            ])
            ->selectSub(
                DB::table('hr_positions')
                    ->select('title')
                    ->whereColumn('id', 'staff.primary_position_id')
                    ->limit(1),
                'position_title',
            )
            ->get()
            ->map(static fn (object $row): array => [
                'id' => (string) $row->id,
                'staffNumber' => (string) $row->staff_number,
                'name' => trim(implode(' ', array_filter([
                    $row->first_name,
                    $row->middle_name,
                    $row->last_name,
                ]))),
                'status' => (string) $row->staff_status,
                'phone' => $row->phone === null ? null : (string) $row->phone,
                'email' => (string) $row->email,
                'positionTitle' => $row->position_title === null ? null : (string) $row->position_title,
            ])->all());
    }

    public function pendingAssignmentRequestCount(string $tenantId, string $facilityId): int
    {
        return DB::table('hr_assignment_requests')
            ->where('tenant_id', $tenantId)
            ->where('facility_id', $facilityId)
            ->where('status', 'PENDING')
            ->count();
    }

    private function currentAssignmentQuery(
        string $tenantId,
        string $facilityId,
        string $today,
    ): Builder {
        return DB::table('hr_assignments as assignment')
            ->where('assignment.tenant_id', $tenantId)
            ->where('assignment.facility_id', $facilityId)
            ->where('assignment.status', 'ACTIVE')
            ->where('assignment.effective_from', '<=', $today)
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>=', $today));
    }

    /** @param array<string, mixed> $row */
    private function accountState(array $row): string
    {
        if ($row['linked_user_id'] === null) {
            return 'NO_ACCOUNT';
        }
        if ($row['email_verified_at'] !== null) {
            return 'ACTIVE';
        }

        $status = $row['invitation_status'] === null ? null : (string) $row['invitation_status'];
        if ($status === 'PENDING'
            && $row['invitation_expires_at'] !== null
            && now()->lt($row['invitation_expires_at'])) {
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
}
